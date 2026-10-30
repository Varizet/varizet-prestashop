<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Support;

use Varizet\Exception\NetworkException;
use Varizet\Http\HttpClientInterface;
use Varizet\Http\HttpRequest;
use Varizet\Http\HttpResponse;

final class FakeHttp implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|NetworkException> */
    private array $queue = [];

    public function json(int $status, array $body, array $headers = []): self
    {
        $this->queue[] = new HttpResponse($status, $headers + ['content-type' => 'application/json'], (string) json_encode($body, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function fail(): self
    {
        $this->queue[] = new NetworkException('Connection refused');
        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new \LogicException('unexpected ' . $request->method . ' ' . $request->url);
        if ($next instanceof NetworkException) {
            throw $next;
        }
        return $next;
    }

    /** @return array<string, mixed> */
    public function body(int $i): array
    {
        return json_decode((string) $this->requests[$i]->body, true);
    }
}
