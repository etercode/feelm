<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds CORS headers so the SvelteKit frontend (a different origin) can call the API.
 * In dev, any Origin is accepted. In prod, only origins listed in CORS_ALLOWED_ORIGINS.
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private readonly array $allowedOrigins;

    public function __construct(
        ?string $allowedOrigins,
        private readonly string $kernelEnvironment,
    ) {
        $allowedOrigins = trim($allowedOrigins ?? '');
        $this->allowedOrigins = '' === $allowedOrigins
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $allowedOrigins))));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('OPTIONS')) {
            return;
        }

        if ('prod' === $this->kernelEnvironment && !$this->isOriginAllowed($request->headers->get('Origin'))) {
            return;
        }

        $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $origin = $event->getRequest()->headers->get('Origin');
        if (null === $origin || '' === $origin) {
            return;
        }

        if (!$this->isOriginAllowed($origin)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $request = $event->getRequest();

        $headers->set('Access-Control-Allow-Origin', $origin);
        $headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $headers->set('Access-Control-Allow-Headers', $request->headers->get('Access-Control-Request-Headers', 'Content-Type, Authorization'));
        $headers->set('Access-Control-Allow-Credentials', 'true');
        $headers->set('Access-Control-Max-Age', '3600');
        $headers->set('Vary', 'Origin', false);
    }

    private function isOriginAllowed(?string $origin): bool
    {
        if (null === $origin || '' === $origin) {
            return false;
        }

        if ('prod' !== $this->kernelEnvironment) {
            return true;
        }

        return \in_array($origin, $this->allowedOrigins, true);
    }
}
