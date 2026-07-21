<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Http;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Adds bounded retry-with-backoff around the shared Guzzle client.
 *
 * For the AI matcher and embedding provider bases, whose $httpClient throws on
 * a 5xx/429 (Guzzle's default http_errors). A transport error or a retriable
 * status is retried up to $maxRetries times, respecting a server Retry-After;
 * anything else (e.g. 400/401) rethrows immediately.
 */
trait RetriesHttp {

  /**
   * Like $this->httpClient->request(), but retries transient failures.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   The last error, when retries are exhausted or the failure is not transient.
   */
  protected function requestWithRetry(string $method, string $uri, array $options, int $maxRetries = 2): ResponseInterface {
    $attempt = 0;
    while (TRUE) {
      try {
        return $this->httpClient->request($method, $uri, $options);
      }
      catch (GuzzleException $e) {
        $response = $e instanceof RequestException ? $e->getResponse() : NULL;
        $status = $response ? $response->getStatusCode() : NULL;
        if ($attempt >= $maxRetries || !Backoff::retriable($status)) {
          throw $e;
        }
        $retryAfter = $response && $response->hasHeader('Retry-After')
          ? (int) $response->getHeaderLine('Retry-After')
          : NULL;
        sleep(Backoff::seconds($attempt, $retryAfter ?: NULL));
        $attempt++;
      }
    }
  }

}
