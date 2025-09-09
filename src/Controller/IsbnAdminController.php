<?php

namespace Drupal\wlt_bookshop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\NodeInterface;
use GuzzleHttp\Exception\RequestException;

class IsbnAdminController extends ControllerBase {

  public function remove(Request $request, NodeInterface $node): JsonResponse {
    $value = (string) $request->query->get('value');
    $token = (string) $request->query->get('token');
    if ($value === '') {
      return new JsonResponse(['ok' => false, 'error' => 'missing value'], 400);
    }
    // Validate CSRF token bound to node id + value.
    $expected = 'wlt_bookshop:remove:' . $node->id() . ':' . $value;
    if (!$this->csrfToken()->validate($token, $expected)) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid token'], 400);
    }
    if (!$node->access('update')) {
      return new JsonResponse(['ok' => false, 'error' => 'no access'], 403);
    }
    if (!$node->hasField('field_isbn')) {
      return new JsonResponse(['ok' => false, 'error' => 'field missing'], 400);
    }

    $items = $node->get('field_isbn');
    $kept = [];
    $removed = 0;
    foreach ($items as $item) {
      $v = isset($item->value) ? (string) $item->value : '';
      if ($v === $value) {
        $removed++;
        continue;
      }
      if ($v !== '') {
        $kept[] = ['value' => $v];
      }
    }
    if ($removed > 0) {
      $node->set('field_isbn', $kept);
      $node->save();
    }

    return new JsonResponse(['ok' => true, 'removed' => $removed]);
  }

  public function report(Request $request, NodeInterface $node): JsonResponse {
    $value = (string) $request->query->get('value');
    $token = (string) $request->query->get('token');
    if ($value === '') {
      return new JsonResponse(['ok' => false, 'error' => 'missing value'], 400);
    }
    $expected = 'wlt_bookshop:report:' . $node->id() . ':' . $value;
    if (!$this->csrfToken()->validate($token, $expected)) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid token'], 400);
    }

    $key = 'nid:' . $node->id() . ':isbn:' . $value;
    $expire = \Drupal::time()->getRequestTime() + 60 * 60 * 24 * 30; // 30 days.
    \Drupal::cache('wlt_bookshop_bad_isbn')->set($key, TRUE, $expire);
    \Drupal::logger('wlt_bookshop')->notice('Suppressed ISBN @isbn for node @nid for 30 days.', ['@isbn' => $value, '@nid' => $node->id()]);
    return new JsonResponse(['ok' => true, 'suppressed_until' => $expire]);
  }

  /**
   * Lightweight server-side HEAD/GET to check an embed URL.
   *
   * Query: ?url=... (must be https://bookshop.org/...)
   * Returns JSON: { ok: bool, status: int, x_frame_options: string|null }
   */
  public function check(Request $request): JsonResponse {
    $url = (string) $request->query->get('url');
    if ($url === '' || !preg_match('#^https://bookshop\.org/#i', $url)) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid url'], 400);
    }
    $client = \Drupal::httpClient();
    $status = 0;
    $xfo = NULL;
    try {
      // Try HEAD first.
      $resp = $client->request('HEAD', $url, [
        'timeout' => 6,
        'connect_timeout' => 4,
        'allow_redirects' => [ 'track_redirects' => true, 'max' => 4 ],
        'http_errors' => false,
      ]);
      $status = (int) $resp->getStatusCode();
      $xfo = $resp->hasHeader('X-Frame-Options') ? $resp->getHeaderLine('X-Frame-Options') : NULL;
      // Some endpoints may not support HEAD; fallback to GET on 405.
      if ($status === 405) {
        $resp = $client->request('GET', $url, [
          'timeout' => 6,
          'connect_timeout' => 4,
          'allow_redirects' => [ 'track_redirects' => true, 'max' => 4 ],
          'http_errors' => false,
        ]);
        $status = (int) $resp->getStatusCode();
        $xfo = $resp->hasHeader('X-Frame-Options') ? $resp->getHeaderLine('X-Frame-Options') : $xfo;
      }
    }
    catch (RequestException $e) {
      return new JsonResponse(['ok' => false, 'status' => 0, 'x_frame_options' => NULL, 'error' => 'request_failed'], 200);
    }

    // Consider OK only if 2xx and X-Frame-Options is not SAMEORIGIN/DENY.
    $ok = ($status >= 200 && $status < 300);
    if ($xfo) {
      $xf = strtoupper($xfo);
      if (strpos($xf, 'SAMEORIGIN') !== FALSE || strpos($xf, 'DENY') !== FALSE) {
        $ok = false;
      }
    }

    return new JsonResponse([
      'ok' => $ok,
      'status' => $status,
      'x_frame_options' => $xfo,
    ]);
  }
}
