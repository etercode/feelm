<?php

namespace App\Service\Media;

use Aws\CommandPool;
use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use GuzzleHttp\Psr7\Utils;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/**
 * The bucket, behind the two operations this application actually performs.
 *
 * Contabo Object Storage is Ceph behind an S3 façade, and Ceph's dialect is not
 * AWS's — logging is absent, and bucket addressing is path-style rather than
 * the virtual-hosted style the SDK reaches for by default. Hence
 * `use_path_style_endpoint`: without it the SDK asks for
 * `feelm-img.sin1.contabostorage.com`, a host that does not resolve.
 *
 * Keys are the identity of an object here and URLs are not, which is why
 * `url()` is a separate concern from `put()`. What serves the bucket can change
 * — a CDN in front, a different region — without a single stored key changing.
 */
final class ObjectStorage
{
    /**
     * Artwork is immutable: TMDB file names are content hashes, so a given key
     * is always the same bytes. A year is the longest any cache will honour,
     * and `immutable` stops a browser revalidating even on a reload.
     */
    private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    private ?S3Client $client = null;

    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $bucket,
        private readonly string $region,
        /**
         * Contabo's account tenant, the part of the S3 owner id before the `$`.
         *
         * Public reads need it: an object is readable at
         * `<region>.contabostorage.com/<tenant>:<bucket>/<key>` and returns 401
         * without the prefix, whatever ACL the object carries. It is not part
         * of the S3 API — the SDK's own getObjectUrl() builds the 401 form —
         * so it has to be configured rather than discovered.
         */
        private readonly string $tenant = '',
        /**
         * What serves the objects publicly, when something other than the
         * bucket does. This is where a CDN host goes, and setting it is the
         * whole migration to one — no stored key changes.
         */
        private readonly string $publicBaseUrl = '',
    ) {
    }

    /** False when no credentials are configured, so callers can degrade rather than fail. */
    public function isConfigured(): bool
    {
        return '' !== $this->key && '' !== $this->secret && '' !== $this->bucket;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function endpoint(): string
    {
        return sprintf('https://%s.contabostorage.com', $this->region);
    }

    /**
     * Stores bytes under a key and returns that key.
     *
     * Returns the key rather than a URL because the key is what the database
     * keeps — see the note on Work::$posterMirror.
     *
     * @throws AwsException
     */
    public function put(string $key, string $body, string $contentType): string
    {
        $this->client()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
            'CacheControl' => self::CACHE_CONTROL,
            'ACL' => 'public-read',
        ]);

        return $key;
    }

    /**
     * Stores many objects at once, and reports which of them landed.
     *
     * One at a time is hopeless against a distant bucket: a PUT to Singapore
     * from Europe is roughly 400ms of round trip and almost no transfer, so a
     * serial loop runs at two or three images a second — 200 hours for this
     * catalogue. The work is waiting, not computing, so the fix is to have a
     * few dozen requests waiting at once.
     *
     * Returns indexes rather than keys so the caller can match results back to
     * whatever it knows about each object; it is the caller that knows which
     * row and column an upload belongs to, and this class deliberately does not.
     *
     * @param list<array{key: string, body: string, contentType: string}> $objects
     *
     * @return array{stored: list<int>, failed: array<int, string>}
     */
    public function putMany(array $objects, int $concurrency = 25): array
    {
        if ([] === $objects) {
            return ['stored' => [], 'failed' => []];
        }

        $client = $this->client();
        $commands = [];

        foreach ($objects as $object) {
            $commands[] = $client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $object['key'],
                'Body' => $object['body'],
                'ContentType' => $object['contentType'],
                'CacheControl' => self::CACHE_CONTROL,
                'ACL' => 'public-read',
            ]);
        }

        $stored = [];
        $failed = [];

        (new CommandPool($client, $commands, [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => static function ($result, $index) use (&$stored): void {
                $stored[] = $index;
            },
            'rejected' => static function ($reason, $index) use (&$failed): void {
                $failed[$index] = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
            },
        ]))->promise()->wait();

        return ['stored' => $stored, 'failed' => $failed];
    }

    /**
     * Uploads a file in parts, retrying the parts that fail.
     *
     * Takes a path rather than a stream, and that is the whole point. Contabo
     * sheds load — it returned a body the SDK could not parse as XML on part 29
     * of the first real database backup, while the artwork mirror was busy —
     * and recovering from that means sending the failed part again. Re-sending
     * a part means seeking back to it, and the obvious design here, piping
     * pg_dump straight through, cannot: a pipe only goes forwards. So the
     * caller spools to a file first and this reads from that.
     *
     * Three attempts, each resuming from the last one's state so only the
     * missing parts are retried rather than the whole upload.
     *
     * @throws MultipartUploadException when the parts still will not land
     */
    public function putFile(string $key, string $path, string $contentType): string
    {
        $options = [
            'bucket' => $this->bucket,
            'key' => $key,
            // 16 MB: the floor is 5, and at Singapore's 186ms round trip the
            // per-part overhead is what costs, so fewer and larger wins.
            'part_size' => 16 * 1024 * 1024,
            // Three, not the eight the SDK defaults to. This runs alongside the
            // artwork mirror, and that already sits at the level where Contabo
            // starts refusing.
            'concurrency' => 3,
            'params' => ['ContentType' => $contentType],
        ];

        $uploader = new MultipartUploader($this->client(), $path, $options);

        for ($attempt = 1; ; ++$attempt) {
            try {
                $uploader->upload();

                return $key;
            } catch (MultipartUploadException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }

                // Resuming from the failed state re-sends only the parts that
                // did not land; a fresh uploader would start from part one.
                $uploader = new MultipartUploader($this->client(), $path, [
                    ...$options,
                    'state' => $e->getState(),
                ]);
            }
        }
    }

    /**
     * Keys under a prefix, newest first, with their size.
     *
     * @return list<array{key: string, size: int, modified: ?\DateTimeImmutable}>
     */
    public function listKeys(string $prefix): array
    {
        $out = [];
        $token = null;

        do {
            $page = $this->client()->listObjectsV2(array_filter([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'ContinuationToken' => $token,
            ]));

            foreach ($page['Contents'] ?? [] as $object) {
                $out[] = [
                    'key' => (string) $object['Key'],
                    'size' => (int) ($object['Size'] ?? 0),
                    'modified' => isset($object['LastModified'])
                        ? \DateTimeImmutable::createFromInterface($object['LastModified'])
                        : null,
                ];
            }

            $token = $page['IsTruncated'] ?? false ? $page['NextContinuationToken'] ?? null : null;
        } while (null !== $token);

        usort($out, static fn (array $a, array $b) => strcmp($b['key'], $a['key']));

        return $out;
    }

    /**
     * Abandons multipart uploads that were started and never finished.
     *
     * A failed multipart leaves its parts on the server, counted against the
     * quota and belonging to no object — invisible to a listing, so nothing
     * else would ever notice them. The first database backup left 29 parts of
     * one behind when Contabo shed the thirtieth.
     *
     * Age-guarded because an upload in flight right now is not stale, and this
     * runs on a schedule alongside jobs that may be mid-upload.
     *
     * @return int how many were abandoned
     */
    public function abortStaleUploads(int $olderThanHours = 6): int
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d hours', max(1, $olderThanHours)));
        $aborted = 0;

        foreach ($this->client()->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [] as $upload) {
            $started = isset($upload['Initiated'])
                ? \DateTimeImmutable::createFromInterface($upload['Initiated'])
                : null;

            if (null !== $started && $started > $cutoff) {
                continue;
            }

            $this->client()->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $upload['Key'],
                'UploadId' => $upload['UploadId'],
            ]);
            ++$aborted;
        }

        return $aborted;
    }

    public function delete(string $key): void
    {
        $this->client()->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    public function exists(string $key): bool
    {
        try {
            $this->client()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return true;
        } catch (AwsException) {
            return false;
        }
    }

    /** The public URL for a stored key. */
    public function url(string $key): string
    {
        return $this->base().'/'.ltrim($key, '/');
    }

    private function base(): string
    {
        if ('' !== $this->publicBaseUrl) {
            return rtrim($this->publicBaseUrl, '/');
        }

        $path = '' !== $this->tenant ? $this->tenant.':'.$this->bucket : $this->bucket;

        return $this->endpoint().'/'.$path;
    }

    /**
     * Built on first use, not in the constructor: this service is injected into
     * the presenter, which runs on every request that draws a poster, and none
     * of those requests talk to the bucket.
     */
    private function client(): S3Client
    {
        return $this->client ??= new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'endpoint' => $this->endpoint(),
            // Ceph, not AWS — see the class note.
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->key,
                'secret' => $this->secret,
            ],
        ]);
    }
}
