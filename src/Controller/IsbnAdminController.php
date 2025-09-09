<?php

namespace Drupal\wlt_bookshop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\NodeInterface;

class IsbnAdminController extends ControllerBase {

  public function remove(Request $request, NodeInterface $node): JsonResponse {
    $value = (string) $request->query->get('value');
    $token = (string) $request->query->get('token');
    if ($value === '') {
      return new JsonResponse(['ok' => false, 'error' => 'missing value'], 400);
    }
    // Validate CSRF token bound to node id + value.
    $expected = 'wlt_bookshop:remove:' . $node->id() . ':' . $value;
    if (!\Drupal::service('csrf_token')->validate($token, $expected)) {
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
    if (!\Drupal::service('csrf_token')->validate($token, $expected)) {
      return new JsonResponse(['ok' => false, 'error' => 'invalid token'], 400);
    }

    $key = 'nid:' . $node->id() . ':isbn:' . $value;
    $expire = \Drupal::time()->getRequestTime() + 60 * 60 * 24 * 30; // 30 days.
    \Drupal::cache('wlt_bookshop_bad_isbn')->set($key, TRUE, $expire);
    \Drupal::logger('wlt_bookshop')->notice('Suppressed ISBN @isbn for node @nid for 30 days.', ['@isbn' => $value, '@nid' => $node->id()]);
    return new JsonResponse(['ok' => true, 'suppressed_until' => $expire]);
  }
}
