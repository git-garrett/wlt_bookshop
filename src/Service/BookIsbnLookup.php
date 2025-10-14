<?php

namespace Drupal\wlt_bookshop\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Html;

/**
 * Service to look up ISBNs using the Open Library API.
 */
class BookIsbnLookup {

  /** @var \GuzzleHttp\ClientInterface */
  protected $httpClient;

  /** @var \Psr\Log\LoggerInterface */
  protected $logger;

  public function __construct(ClientInterface $http_client, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * Get the first ISBN for a given book title via Open Library.
   *
   * @param string $title
   *   The book title.
   *
   * @return string|null
   *   The first ISBN found, or NULL if none.
   */
  public function getIsbnByTitle(string $title): ?string {
    $title = trim($title);
    if ($title === '') {
      return NULL;
    }

    $query = [
      'title' => $title,
      'limit' => 1,
    ];

    $url = 'https://openlibrary.org/search.json';

    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => $query,
        'timeout' => 5,
        'connect_timeout' => 3,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open Library request failed for title "@title": @message', [
        '@title' => $title,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('Open Library non-200 response (@code) for title "@title".', [
        '@code' => $response->getStatusCode(),
        '@title' => $title,
      ]);
      return NULL;
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || empty($data['docs'][0])) {
      return NULL;
    }

    $doc = $data['docs'][0];
    if (!empty($doc['isbn']) && is_array($doc['isbn'])) {
      // Return the first ISBN string.
      return (string) reset($doc['isbn']);
    }

    return NULL;
  }

  /**
   * Get the first ISBN for a given author via Open Library.
   *
   * Uses the search endpoint filtering by author name and returns
   * the first ISBN found from the first matching work document.
   *
   * @param string $author
   *   The author name.
   *
   * @return string|null
   *   The first ISBN found, or NULL if none.
   */
  public function getIsbnByAuthor(string $author, ?array &$debug = NULL): ?string {
    $author = trim($author);
    if ($author === '') {
      return NULL;
    }

    // 1) Search by author to get works; prefer docs with a cover_edition_key.
    $searchUrl = 'https://openlibrary.org/search.json';
    $query = [
      'author' => $author,
      'limit' => 10,
    ];

    try {
      $response = $this->httpClient->request('GET', $searchUrl, [
        'query' => $query,
        'timeout' => 5,
        'connect_timeout' => 3,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open Library search failed for author "@author": @message', [
        '@author' => $author,
        '@message' => $e->getMessage(),
      ]);
      if (is_array($debug)) {
        $debug['author'] = $author;
        $debug['search'] = [
          'url' => $searchUrl,
          'query' => $query,
          'error' => $e->getMessage(),
        ];
      }
      return NULL;
    }

    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('Open Library search non-200 (@code) for author "@author".', [
        '@code' => $response->getStatusCode(),
        '@author' => $author,
      ]);
      if (is_array($debug)) {
        $debug['author'] = $author;
        $debug['search'] = [
          'url' => $searchUrl,
          'query' => $query,
          'status' => $response->getStatusCode(),
        ];
      }
      return NULL;
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || empty($data['docs']) || !is_array($data['docs'])) {
      if (is_array($debug)) {
        $debug['author'] = $author;
        $debug['search'] = [
          'url' => $searchUrl,
          'query' => $query,
          'decoded' => 'invalid_or_empty',
        ];
      }
      return NULL;
    }

    if (is_array($debug)) {
      $debug['author'] = $author;
      $debug['search'] = [
        'url' => $searchUrl,
        'query' => $query,
        'num_docs' => isset($data['numFound']) ? (int) $data['numFound'] : count($data['docs']),
      ];
      $debug['candidate_edition_keys'] = [];
      $debug['editions'] = [];
    }

    foreach ($data['docs'] as $doc) {
      // Build a list of candidate edition keys from cover_edition_key and edition_key.
      $candidateKeys = [];
      if (!empty($doc['cover_edition_key'])) {
        $candidateKeys[] = (string) $doc['cover_edition_key'];
      }
      if (!empty($doc['edition_key'])) {
        if (is_array($doc['edition_key'])) {
          foreach ($doc['edition_key'] as $ek) {
            $ek = (string) $ek;
            if ($ek !== '') {
              $candidateKeys[] = $ek;
            }
          }
        }
        elseif (is_string($doc['edition_key'])) {
          $candidateKeys[] = $doc['edition_key'];
        }
      }
      if (empty($candidateKeys)) {
        continue;
      }

      if (is_array($debug)) {
        foreach ($candidateKeys as $ek) {
          $debug['candidate_edition_keys'][$ek] = TRUE;
        }
      }

      // Try each candidate edition for an ISBN.
      foreach ($candidateKeys as $editionKey) {
        $editionUrl = 'https://openlibrary.org/books/' . rawurlencode($editionKey) . '.json';

        try {
          $editionResponse = $this->httpClient->request('GET', $editionUrl, [
            'timeout' => 5,
            'connect_timeout' => 3,
          ]);
        }
        catch (\Throwable $e) {
          $this->logger->notice('Open Library edition fetch failed for @edition: @message', [
            '@edition' => $editionKey,
            '@message' => $e->getMessage(),
          ]);
          if (is_array($debug)) {
            $debug['editions'][] = [
              'edition' => $editionKey,
              'url' => $editionUrl,
              'error' => $e->getMessage(),
            ];
          }
          continue;
        }

        if ($editionResponse->getStatusCode() !== 200) {
          $this->logger->notice('Open Library edition non-200 (@code) for @edition.', [
            '@code' => $editionResponse->getStatusCode(),
            '@edition' => $editionKey,
          ]);
          if (is_array($debug)) {
            $debug['editions'][] = [
              'edition' => $editionKey,
              'url' => $editionUrl,
              'status' => $editionResponse->getStatusCode(),
            ];
          }
          continue;
        }

        $edition = json_decode((string) $editionResponse->getBody(), TRUE);
        if (!is_array($edition)) {
          if (is_array($debug)) {
            $debug['editions'][] = [
              'edition' => $editionKey,
              'url' => $editionUrl,
              'decoded' => 'invalid',
            ];
          }
          continue;
        }

        // Prefer ISBN-13, then ISBN-10.
        if (!empty($edition['isbn_13']) && is_array($edition['isbn_13'])) {
          $isbn = (string) reset($edition['isbn_13']);
          if ($isbn !== '') {
            if (is_array($debug)) {
              $debug['editions'][] = [
                'edition' => $editionKey,
                'url' => $editionUrl,
                'isbn_13' => $edition['isbn_13'],
                'picked' => $isbn,
              ];
              $debug['found_isbn'] = $isbn;
            }
            return $isbn;
          }
        }
        if (!empty($edition['isbn_10']) && is_array($edition['isbn_10'])) {
          $isbn = (string) reset($edition['isbn_10']);
          if ($isbn !== '') {
            if (is_array($debug)) {
              $debug['editions'][] = [
                'edition' => $editionKey,
                'url' => $editionUrl,
                'isbn_10' => $edition['isbn_10'],
                'picked' => $isbn,
              ];
              $debug['found_isbn'] = $isbn;
            }
            return $isbn;
          }
        }
      }
    }

    // No ISBNs found from available edition data.
    return NULL;
  }

  /**
   * Get all ISBNs for a given author by traversing edition data.
   *
   * Performs an author search to get works, collects cover_edition_key
   * values, then fetches each edition JSON to aggregate ISBN-13 and ISBN-10.
   *
   * @param string $author
   *   The author name.
   *
   * @return string[]
   *   A de-duplicated list of ISBNs (13 preferred), possibly empty.
   */
  public function getIsbnsByAuthor(string $author, ?array &$debug = NULL): array {
    $author = trim($author);
    if ($author === '') {
      return [];
    }

    $searchUrl = 'https://openlibrary.org/search.json';
    $query = [
      'author' => $author,
      'limit' => 50,
    ];

    try {
      $response = $this->httpClient->request('GET', $searchUrl, [
        'query' => $query,
        'timeout' => 8,
        'connect_timeout' => 4,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open Library search failed for author "@author": @message', [
        '@author' => $author,
        '@message' => $e->getMessage(),
      ]);
      if (is_array($debug)) {
        $debug['author'] = $author;
        $debug['search'] = [
          'url' => $searchUrl,
          'query' => $query,
          'error' => $e->getMessage(),
        ];
      }
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('Open Library search non-200 (@code) for author "@author".', [
        '@code' => $response->getStatusCode(),
        '@author' => $author,
      ]);
      if (is_array($debug)) {
        $debug['author'] = $author;
        $debug['search'] = [
          'url' => $searchUrl,
          'query' => $query,
          'status' => $response->getStatusCode(),
        ];
      }
      return [];
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data) || empty($data['docs']) || !is_array($data['docs'])) {
      if (is_array($debug)) {
        $debug['author'] = $author;
        $debug['search'] = [
          'url' => $searchUrl,
          'query' => $query,
          'decoded' => 'invalid_or_empty',
        ];
      }
      return [];
    }

    $results = [];
    $sequence = 0;
    $loadedEditions = [];
    if (is_array($debug)) {
      $debug['author'] = $author;
      $debug['search'] = [
        'url' => $searchUrl,
        'query' => $query,
        'num_docs' => isset($data['numFound']) ? (int) $data['numFound'] : count($data['docs']),
      ];
      $debug['editions'] = [];
      $debug['works'] = [];
    }

    $seenWorks = [];
    foreach ($data['docs'] as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $workKey = isset($doc['key']) ? (string) $doc['key'] : '';
      if ($workKey !== '' && isset($seenWorks[$workKey])) {
        continue;
      }
      if ($workKey !== '') {
        $seenWorks[$workKey] = TRUE;
      }
      $normalizedWork = $this->normalizeWorkKey($workKey);
      $coverKey = !empty($doc['cover_edition_key']) ? (string) $doc['cover_edition_key'] : '';

      if ($coverKey !== '') {
        $this->appendEditionIsbns($coverKey, $normalizedWork, $results, $sequence, $debug, $loadedEditions, TRUE);
      }

      if (!empty($doc['edition_key'])) {
        $editionKeys = [];
        if (is_array($doc['edition_key'])) {
          $editionKeys = array_map('strval', $doc['edition_key']);
        }
        elseif (is_string($doc['edition_key'])) {
          $editionKeys = [(string) $doc['edition_key']];
        }
        foreach ($editionKeys as $editionKey) {
          if ($editionKey === '' || $editionKey === $coverKey) {
            continue;
          }
          $this->appendEditionIsbns($editionKey, $normalizedWork, $results, $sequence, $debug, $loadedEditions, FALSE);
        }
      }

      if ($workKey !== '') {
        $this->appendWorkEditionIsbns($workKey, $coverKey, $results, $sequence, $debug, $loadedEditions);
      }
    }

    $final = $this->finalizeIsbnResults($results);
    if (is_array($debug)) {
      $debug['found_isbns'] = $final;
    }
    return $final;
  }

  /**
   * Fetch the JSON for a specific edition and append ISBNs.
   */
  protected function appendEditionIsbns(string $editionKey, string $workKey, array &$results, int &$sequence, ?array &$debug, array &$loadedEditions, bool $preferred = FALSE): void {
    $editionKey = trim($editionKey);
    if ($editionKey === '' || isset($loadedEditions[$editionKey])) {
      return;
    }
    $loadedEditions[$editionKey] = TRUE;

    $editionUrl = 'https://openlibrary.org/books/' . rawurlencode($editionKey) . '.json';
    try {
      $response = $this->httpClient->request('GET', $editionUrl, [
        'timeout' => 8,
        'connect_timeout' => 4,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->notice('Open Library edition fetch failed for @edition: @message', [
        '@edition' => $editionKey,
        '@message' => $e->getMessage(),
      ]);
      if (is_array($debug)) {
        $debug['editions'][] = [
          'edition' => $editionKey,
          'url' => $editionUrl,
          'error' => $e->getMessage(),
        ];
      }
      return;
    }

    if ($response->getStatusCode() !== 200) {
      if (is_array($debug)) {
        $debug['editions'][] = [
          'edition' => $editionKey,
          'url' => $editionUrl,
          'status' => $response->getStatusCode(),
        ];
      }
      return;
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload)) {
      if (is_array($debug)) {
        $debug['editions'][] = [
          'edition' => $editionKey,
          'url' => $editionUrl,
          'decoded' => 'invalid',
        ];
      }
      return;
    }

    $normalizedWork = $this->normalizeWorkKey($workKey, $payload['works'] ?? [], $editionKey);
    $format = $this->determineFormat($payload);

    $isbns = [];
    if (!empty($payload['isbn_13']) && is_array($payload['isbn_13'])) {
      $isbns = array_merge($isbns, array_map('strval', $payload['isbn_13']));
    }
    if (!empty($payload['isbn_10']) && is_array($payload['isbn_10'])) {
      $isbns = array_merge($isbns, array_map('strval', $payload['isbn_10']));
    }

    foreach ($isbns as $isbn) {
      $this->storeIsbnEntry($isbn, $normalizedWork, $format, $preferred, $results, $sequence);
    }

    if (is_array($debug)) {
      $entry = [
        'edition' => $editionKey,
        'url' => $editionUrl,
        'format' => $format,
      ];
      if (!empty($payload['isbn_13'])) {
        $entry['isbn_13'] = $payload['isbn_13'];
      }
      if (!empty($payload['isbn_10'])) {
        $entry['isbn_10'] = $payload['isbn_10'];
      }
      if ($preferred) {
        $entry['preferred'] = TRUE;
      }
      $debug['editions'][] = $entry;
    }
  }

  /**
   * Fetch editions for a work and append any additional ISBNs found.
   */
  protected function appendWorkEditionIsbns(string $workKey, string $coverKey, array &$results, int &$sequence, ?array &$debug, array &$loadedEditions): void {
    $workKey = trim($workKey);
    if ($workKey === '') {
      return;
    }
    $url = 'https://openlibrary.org' . $workKey . '/editions.json?limit=500';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'connect_timeout' => 4,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->notice('Open Library editions fetch failed for @work: @message', [
        '@work' => $workKey,
        '@message' => $e->getMessage(),
      ]);
      if (is_array($debug)) {
        $debug['works'][] = [
          'work' => $workKey,
          'url' => $url,
          'error' => $e->getMessage(),
        ];
      }
      return;
    }

    if ($response->getStatusCode() !== 200) {
      if (is_array($debug)) {
        $debug['works'][] = [
          'work' => $workKey,
          'url' => $url,
          'status' => $response->getStatusCode(),
        ];
      }
      return;
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload) || empty($payload['entries']) || !is_array($payload['entries'])) {
      return;
    }

    $normalizedWork = $this->normalizeWorkKey($workKey);
    foreach ($payload['entries'] as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (!empty($entry['key']) && is_string($entry['key'])) {
        $editionKey = ltrim($entry['key'], '/');
        $editionKey = preg_replace('/^books\//', '', $editionKey);
        if ($editionKey !== '' && $editionKey !== $coverKey) {
          $this->appendEditionIsbns($editionKey, $normalizedWork, $results, $sequence, $debug, $loadedEditions, FALSE);
        }
        continue;
      }

      $isbns = [];
      if (!empty($entry['isbn_13']) && is_array($entry['isbn_13'])) {
        $isbns = array_merge($isbns, array_map('strval', $entry['isbn_13']));
      }
      if (!empty($entry['isbn_10']) && is_array($entry['isbn_10'])) {
        $isbns = array_merge($isbns, array_map('strval', $entry['isbn_10']));
      }
      if (empty($isbns)) {
        continue;
      }
      $format = $this->determineFormat($entry);
      foreach ($isbns as $isbn) {
        $this->storeIsbnEntry($isbn, $normalizedWork, $format, FALSE, $results, $sequence);
      }
    }
  }

  protected function normalizeWorkKey(?string $workKey, array $works = [], ?string $editionKey = NULL): string {
    if (is_string($workKey) && $workKey !== '') {
      return $workKey;
    }
    foreach ($works as $workRef) {
      if (is_array($workRef) && !empty($workRef['key'])) {
        return (string) $workRef['key'];
      }
      if (is_string($workRef) && $workRef !== '') {
        return $workRef;
      }
    }
    if ($editionKey !== NULL && $editionKey !== '') {
      return '_edition:' . $editionKey;
    }
    static $fallback = 0;
    $fallback++;
    return '_unknown:' . $fallback;
  }

  protected function determineFormat(array $data): string {
    $fragments = [];
    foreach (['physical_format', 'physical_format_detail', 'medium'] as $key) {
      if (!empty($data[$key]) && is_string($data[$key])) {
        $fragments[] = mb_strtolower($data[$key]);
      }
    }
    foreach (['title', 'subtitle', 'edition_name'] as $key) {
      if (!empty($data[$key]) && is_string($data[$key])) {
        $fragments[] = mb_strtolower($data[$key]);
      }
    }
    if (!empty($data['subjects']) && is_array($data['subjects'])) {
      foreach ($data['subjects'] as $subject) {
        if (is_string($subject)) {
          $fragments[] = mb_strtolower($subject);
        }
      }
    }
    if (!empty($data['physical_description']) && is_string($data['physical_description'])) {
      $fragments[] = mb_strtolower($data['physical_description']);
    }

    $text = trim(implode(' ', $fragments));
    if ($text === '') {
      return 'other';
    }

    if (str_contains($text, 'paperback') || str_contains($text, 'softcover') || str_contains($text, 'soft cover') || str_contains($text, 'softback') || str_contains($text, 'trade paper')) {
      return 'paperback';
    }
    if (str_contains($text, 'hardcover') || str_contains($text, 'hardback') || str_contains($text, 'hard cover') || str_contains($text, 'cloth') || str_contains($text, 'library binding')) {
      return 'hardcover';
    }
    if (str_contains($text, 'audio') || str_contains($text, 'sound recording') || str_contains($text, 'cd') || str_contains($text, 'mp3') || str_contains($text, 'spoken word')) {
      return 'audio';
    }
    return 'other';
  }

  protected function formatScore(string $format): int {
    return match ($format) {
      'paperback' => 3,
      'hardcover' => 2,
      'audio' => 1,
      default => 2,
    };
  }

  protected function storeIsbnEntry(string $isbn, string $workKey, string $format, bool $preferred, array &$results, int &$sequence): void {
    $normalized = preg_replace('/[^0-9X]/i', '', $isbn);
    if ($normalized === '') {
      return;
    }
    $entry = [
      'isbn' => $normalized,
      'score' => $this->formatScore($format),
      'order' => $sequence++,
      'preferred' => $preferred,
      'format' => $format,
      'work' => $workKey,
    ];

    if (!isset($results[$normalized])) {
      $results[$normalized] = $entry;
      return;
    }

    if ($this->shouldReplaceResult($results[$normalized], $entry)) {
      $entry['order'] = min($entry['order'], $results[$normalized]['order']);
      $results[$normalized] = $entry;
    }
  }

  protected function shouldReplaceResult(array $existing, array $candidate): bool {
    if ($candidate['score'] > $existing['score']) {
      return TRUE;
    }
    if ($candidate['score'] < $existing['score']) {
      return FALSE;
    }
    if ($candidate['preferred'] && !$existing['preferred']) {
      return TRUE;
    }
    if (!$candidate['preferred'] && $existing['preferred']) {
      return FALSE;
    }
    return $candidate['order'] < $existing['order'];
  }

  protected function finalizeIsbnResults(array $results): array {
    if (empty($results)) {
      return [];
    }

    $byWork = [];
    foreach ($results as $isbn => $info) {
      $workKey = $info['work'] ?: '_unknown';
      if (!isset($byWork[$workKey])) {
        $byWork[$workKey] = [
          'entries' => [],
          'minOrder' => $info['order'],
        ];
      }
      $byWork[$workKey]['entries'][$isbn] = $info;
      $byWork[$workKey]['minOrder'] = min($byWork[$workKey]['minOrder'], $info['order']);
    }

    foreach ($byWork as $workKey => $data) {
      $entries = $data['entries'];
      $maxScore = max(array_column($entries, 'score'));
      if ($maxScore >= 3) {
        $entries = array_filter($entries, static fn($entry) => $entry['score'] >= 3);
      }
      elseif ($maxScore >= 2) {
        $entries = array_filter($entries, static fn($entry) => $entry['score'] >= 2);
      }
      uasort($entries, static function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
          return $b['score'] <=> $a['score'];
        }
        if ($a['preferred'] !== $b['preferred']) {
          return $a['preferred'] ? -1 : 1;
        }
        return $a['order'] <=> $b['order'];
      });
      $byWork[$workKey]['entries'] = $entries;
    }

    uasort($byWork, static function (array $a, array $b): int {
      return $a['minOrder'] <=> $b['minOrder'];
    });

    $final = [];
    foreach ($byWork as $workData) {
      foreach ($workData['entries'] as $isbn => $info) {
        $final[] = $isbn;
      }
    }

    return $final;
  }

  /**
   * Get EANs by searching Bookshop.org with title + author keywords.
   *
   * Builds https://bookshop.org/books?keywords={urlencoded(title + authors)}
   * Fetches HTML, finds first 3 product links (href starts with /p/books/),
   * then extracts EANs from href via query param `ean` or 13-digit segments.
   *
   * @param string $title
   *   The book title string.
   * @param string[] $authors
   *   One or more author names.
   * @param array|null $debug
   *   Optional debug container (by ref) to collect request/parse details.
   *
   * @return string[]
   *   Unique EAN-13 values found, in discovery order.
   */
  public function getEansByTitleAuthor(string $title, array $authors = [], ?array &$debug = NULL): array {
    $title = $this->sanitizePlain($title);
    $authors = array_values(array_filter(array_map(function ($v) { return $this->sanitizePlain($v); }, $authors), static function($v){ return $v !== ''; }));
    if ($title === '' && empty($authors)) {
      return [];
    }

    $keywords = trim($title . ' ' . implode(' ', $authors));
    $url = 'https://bookshop.org/books';
    $query = ['keywords' => $keywords];

    if (is_array($debug)) {
      $debug['bookshop'] = [
        'url' => $url,
        'query' => $query,
      ];
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => $query,
        'timeout' => 10,
        'connect_timeout' => 5,
        'headers' => [
          'User-Agent' => 'Drupal-wlt_bookshop/1.0 (+https://example.com) PHP',
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Bookshop search failed for "@kw": @message', [
        '@kw' => $keywords,
        '@message' => $e->getMessage(),
      ]);
      if (is_array($debug)) {
        $debug['bookshop']['error'] = $e->getMessage();
      }
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('Bookshop search non-200 (@code) for "@kw"', [
        '@code' => $response->getStatusCode(),
        '@kw' => $keywords,
      ]);
      if (is_array($debug)) {
        $debug['bookshop']['status'] = $response->getStatusCode();
      }
      return [];
    }

    $html = (string) $response->getBody();
    $hrefs = $this->extractBookshopProductLinks($html, 3);
    $eans = [];
    foreach ($hrefs as $href) {
      foreach ($this->extractEansFromHref($href) as $ean) {
        $eans[$ean] = TRUE;
      }
    }
    if (is_array($debug)) {
      $debug['bookshop']['links'] = $hrefs;
      $debug['bookshop']['eans'] = array_keys($eans);
    }
    return array_keys($eans);
  }

  /**
   * Convert HTML-ish input to a plain text search string.
   */
  protected function sanitizePlain(string $text): string {
    if ($text === '') { return ''; }
    // Decode HTML entities, strip tags, collapse whitespace.
    $text = Html::decodeEntities($text);
    $text = strip_tags($text);
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    return $text;
  }

  /**
   * Extract the first N product links from Bookshop HTML.
   *
   * @param string $html
   * @param int $limit
   * @return string[] Relative hrefs like /p/books/.../12345?ean=978...
   */
  protected function extractBookshopProductLinks(string $html, int $limit = 3): array {
    $hrefs = [];
    if ($html === '') { return $hrefs; }
    // Use DOM parsing to find anchors starting with /p/books/
    $dom = new \DOMDocument();
    // Suppress warnings from malformed HTML.
    @$dom->loadHTML($html);
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//a[starts-with(@href, "/p/books/")]/@href');
    if ($nodes) {
      foreach ($nodes as $attr) {
        $href = (string) $attr->nodeValue;
        if ($href !== '') {
          $hrefs[] = $href;
          if (count($hrefs) >= $limit) { break; }
        }
      }
    }
    return $hrefs;
  }

  /**
   * Extract EANs from a Bookshop product href.
   *
   * Parses query param `ean` if present; otherwise, looks for 13-digit tokens
   * in the path segments.
   *
   * @param string $href
   * @return string[] EAN-13 values.
   */
  protected function extractEansFromHref(string $href): array {
    $out = [];
    $parts = @parse_url($href);
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $qs);
      if (!empty($qs['ean'])) {
        $ean = preg_replace('/\D+/', '', (string) $qs['ean']);
        if ($ean !== '' && strlen($ean) >= 12) {
          $out[$ean] = TRUE;
        }
      }
    }
    if (!empty($parts['path'])) {
      // Find 13-digit sequences in the path.
      if (preg_match_all('/(^|\D)(\d{13})(?=\D|$)/', $parts['path'], $m)) {
        foreach ($m[2] as $ean) {
          $out[$ean] = TRUE;
        }
      }
    }
    return array_keys($out);
  }

}
