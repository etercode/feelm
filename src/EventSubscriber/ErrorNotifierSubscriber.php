<?php

namespace App\EventSubscriber;

use App\Service\Notify\TelegramNotifier;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * "Errors that need attention", sent to Telegram.
 *
 * A kernel listener rather than a log handler because this application has no
 * monolog-bundle, and adding one for a single feature is a dependency, a config
 * file and an image rebuild to deliver something an event subscriber already
 * does. Everything that reaches a user as a 500 passes through here.
 *
 * ---- what is skipped --------------------------------------------------------
 *
 * Anything under 500. A 404 is a bad link, a 422 is a bad form, a 401 is
 * somebody signed out — none of them is a fault, and a channel that reports
 * them is a channel nobody reads. Only what the server got wrong.
 *
 * ---- why it throttles -------------------------------------------------------
 *
 * The failure worth being told about is usually the one that is happening to
 * everybody at once: a database that went away, a migration half-applied. That
 * is thousands of identical exceptions a minute, and a bot that forwards all of
 * them turns the alert into the outage. One message per distinct fault per
 * fifteen minutes says the same thing and stays readable.
 */
class ErrorNotifierSubscriber implements EventSubscriberInterface
{
    private const QUIET_FOR = 900;

    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Late, so anything that turns an exception into a proper response —
        // a validation failure becoming a 422 — has already had its say.
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();

        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500) {
            return;
        }

        try {
            if (!$this->telegram->wants('error')) {
                return;
            }

            // The place in the code, not the message: two failures of the same
            // query with different ids are one fault, and keying on the text
            // would let them through as thousands.
            $key = 'notify.error.'.hash('xxh128', $error::class.$error->getFile().$error->getLine());
            $item = $this->cache->getItem($key);

            if ($item->isHit()) {
                return;
            }

            $item->set(true)->expiresAfter(self::QUIET_FOR);
            $this->cache->save($item);

            $request = $event->getRequest();

            $this->telegram->report(
                'Server error',
                false,
                [
                    'Where' => $request->getMethod().' '.$request->getPathInfo(),
                    'Error' => (new \ReflectionClass($error))->getShortName(),
                    'Message' => mb_substr($error->getMessage(), 0, 300),
                    'At' => basename($error->getFile()).':'.$error->getLine(),
                ],
                'error',
            );
        } catch (\Throwable) {
            // The one thing this must never do is add a second exception to the
            // one already being handled.
        }
    }
}
