<?php
/*
 * Copyright (c) Nate Brunette.
 * Distributed under the MIT License (http://opensource.org/licenses/MIT)
 */

declare(strict_types=1);

namespace Tebru\RetrofitHttp\Guzzle6;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tebru\Retrofit\HttpClient;

/**
 * Class Guzzle6HttpClient
 *
 * @author Nate Brunette <n@tebru.net>
 */
class Guzzle6HttpClient implements HttpClient
{
    /**
     * @var Client
     */
    private $client;

    /**
     * An array of pending requests
     *
     * @var array
     */
    private $requests = [];

    /**
     * How many asynchronous request to make at once
     *
     * @var int
     */
    private $concurrency;

    /**
     * Constructor
     *
     * @param ClientInterface $client
     * @param int $concurrency
     */
    public function __construct(ClientInterface $client, int $concurrency = 5)
    {
        $this->client = $client;
        $this->concurrency = $concurrency;
    }

    /**
     * Send a request synchronously and return a PSR-7 [@see ResponseInterface]
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws \GuzzleHttp\Exception\RequestException
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->client->send($request);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
            if ($response === null) {
                throw $exception;
            }
        }

        return $response;
    }

    /**
     * Send a request asynchronously
     *
     * The response callback must be called if any response is returned from the request, and the failure
     * callback should only be executed if a request was not completed.
     *
     * The response callback should pass a PSR-7 [@see ResponseInterface] as the one and only argument. The
     * failure callback should pass a [@see Throwable] as the one and only argument.
     *
     * @param RequestInterface $request
     * @param callable $onResponse
     * @param callable $onFailure
     * @return void
     */
    public function sendAsync(RequestInterface $request, callable $onResponse, callable $onFailure): void
    {
        $this->requests[] = [
            'promise' => $this->client->sendAsync($request),
            'onResponse' => $onResponse,
            'onFailure' => $onFailure,
        ];
    }

    /**
     * Calling this method should execute any enqueued requests asynchronously
     *
     * @return void
     * @throws \LogicException
     */
    public function wait(): void
    {
        if ($this->requests === []) {
            return;
        }

        $requestList = $this->requests;
        $this->requests = [];

        $requests = function () use ($requestList) {
            foreach ($requestList as $request) {
                yield function () use ($request) {
                    return $request['promise'];
                };
            }
        };

        $pool = new Pool( $this->client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (ResponseInterface $response, $index) use ($requestList) {
                return $requestList[$index]['onResponse']($response);
            },
            'rejected' => function ($reason, $index) use ($requestList) {
                if ($reason instanceof RequestException && $reason->getResponse() !== null) {
                    return $requestList[$index]['onResponse']($reason->getResponse());
                }
                return $requestList[$index]['onFailure']($reason);
            },
        ]);

        $pool->promise()->wait();
    }
}
