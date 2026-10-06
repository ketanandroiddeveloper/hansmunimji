<?php

declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public function __construct(private Config $config, private Logger $logger)
    {
    }

    public function render(\Throwable $e, Request $request): Response
    {
        $requestId = (string) $request->attribute('request_id', '');

        if ($e instanceof HttpException) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage(), 'request_id' => $requestId];
            if ($e->fields !== []) {
                $error['fields'] = $e->fields;
            }
            if ($e->status >= 500) {
                $this->logger->error('http_error', ['code' => $e->errorCode, 'request_id' => $requestId]);
            }
            $response = Response::rawJson(['error' => $error], $e->status);
            foreach ($e->headers as $name => $value) {
                $response->header($name, (string) $value);
            }

            return $response;
        }

        $this->logger->error('unhandled_exception', [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'path' => $request->path,
            'request_id' => $requestId,
        ]);

        $error = [
            'code' => 'server_error',
            'message' => 'Something went wrong on our side. Please try again shortly.',
            'request_id' => $requestId,
        ];
        if ($this->config->get('app.debug') && !$this->config->isProduction()) {
            $error['debug'] = ['type' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()];
        }

        return Response::rawJson(['error' => $error], 500);
    }
}
