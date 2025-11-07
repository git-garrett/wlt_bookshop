<?php

namespace Drupal\wlt_bookshop\Service;

use Drupal\Component\Utility\Html;
use Drupal\wlt_bookshop\Exception\OpenLibraryApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to look up ISBNs using the Open Library API.
 */
class BookIsbnLookup {
  public const API_STAT_KEYS = ['search', 'edition', 'work'];

  private const OPEN_LIBRARY_USER_AGENT = 'WorldLiteratureToday (staff@evenvision.com)';
  private const OPEN_LIBRARY_RATE_LIMIT = 1; 
  private const OPEN_LIBRARY_WINDOW_SECONDS = 2; 

  /** @var \GuzzleHttp\ClientInterface */
  protected $httpClient;

  /** @var \Psr\Log\LoggerInterface */
  protected $logger;

  /**
   * Cached author lookups for the current request when debug is disabled.
   *
   * @var array<string, array>
   */
  protected array $authorCache = [];

  /**
   * Cached edition payloads keyed by edition identifier.
   *
   * Stores FALSE for failed lookups to avoid repeated HTTP requests.
   *
   * @var array<string, array|false>
   */
  protected array $editionCache = [];

  /**
   * Cached work editions payloads keyed by work identifier.
   *
   * Stores FALSE for failed lookups to avoid repeated HTTP requests.
   *
   * @var array<string, array|false>
   */
  protected array $workEditionsCache = [];

  /**
   * Start of the current Open Library rate limit window (microtime, seconds).
   */
  protected float $openLibraryWindowStart = 0.0;

  /**
   * Number of requests issued within the current Open Library window.
   */
  protected int $openLibraryWindowCount = 0;

  /**
   * Tracks counts of Open Library API calls per category.
   *
   * @var array<string,int>
   */
  protected array $apiStats = [
    'search' => 0,
    'edition' => 0,
    'work' => 0,
    'other' => 0,
  ];

  public function __construct(ClientInterface $http_client, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * Retrieve current API call statistics.
   */
  public function getApiStats(): array {
    return $this->apiStats;
  }

  /**
   * Apply shared defaults to Open Library HTTP options.
   */
  protected function prepareOpenLibraryOptions(array $options = []): array {
    $options['http_errors'] = FALSE;
    $headers = $options['headers'] ?? [];
    $headers['User-Agent'] = self::OPEN_LIBRARY_USER_AGENT;
    $options['headers'] = $headers;
    return $options;
  }

  /**
   * Increment the API statistics bucket for the given category.
   */
  protected function incrementApiStat(string $category): void {
    if (!isset($this->apiStats[$category])) {
      $category = 'other';
    }
    $this->apiStats[$category]++;
  }

  /**
   * Sleep as necessary to respect Open Library's request rate.
   */
  protected function throttleOpenLibraryRequests(): void {
    $now = microtime(TRUE);
    $window = self::OPEN_LIBRARY_WINDOW_SECONDS;
    if ($this->openLibraryWindowStart === 0.0 || ($now - $this->openLibraryWindowStart) >= $window) {
      $this->openLibraryWindowStart = $now;
      $this->openLibraryWindowCount = 0;
    }
    if ($this->openLibraryWindowCount >= self::OPEN_LIBRARY_RATE_LIMIT) {
      $sleepSeconds = ($this->openLibraryWindowStart + $window) - $now;
      if ($sleepSeconds > 0) {
        usleep((int) ceil($sleepSeconds * 1_000_000));
      }
      $now = microtime(TRUE);
      $this->openLibraryWindowStart = $now;
      $this->openLibraryWindowCount = 0;
    }
    $this->openLibraryWindowCount++;
  }

  /**
   * Abort processing when Open Library indicates access should stop.
   */
  protected function guardOpenLibraryResponse(string $url, ResponseInterface $response, string $context): void {
    $status = $response->getStatusCode();
    if ($status === 403 || $status === 429) {
      $this->logger->error('Open Library returned status @code during @context request (@url); aborting lookup.', [
        '@code' => $status,
        '@context' => $context,
        '@url' => $url,
      ]);
      throw new OpenLibraryApiException(sprintf('Open Library returned status %d for %s (%s)', $status, $context, $url), $status);
    }
  }

  /**
   * Execute a throttled Open Library GET request.
   *
   * @throws \Drupal\wlt_bookshop\Exception\OpenLibraryApiException
   *   When Open Library signals access should be halted.
   */
  protected function sendOpenLibraryGet(string $url, array $options, string $context, string $category = 'other'): ResponseInterface {
    $options = $this->prepareOpenLibraryOptions($options);
    $this->throttleOpenLibraryRequests();
    $this->incrementApiStat($category);
    $response = $this->httpClient->request('GET', $url, $options);
    $this->guardOpenLibraryResponse($url, $response, $context);
    return $response;
  }

  /**
   * Queue a throttled Open Library GET request asynchronously.
   */
  protected function sendOpenLibraryGetAsync(string $url, array $options, string $context, string $category = 'other'): PromiseInterface {
    $options = $this->prepareOpenLibraryOptions($options);
    $this->throttleOpenLibraryRequests();
    $this->incrementApiStat($category);
    return $this->httpClient->requestAsync('GET', $url, $options)
      ->then(function (ResponseInterface $response) use ($url, $context) {
        $this->guardOpenLibraryResponse($url, $response, $context);
        return $response;
      });
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
      $response = $this->sendOpenLibraryGet($url, [
        'query' => $query,
        'timeout' => 5,
        'connect_timeout' => 3,
      ], 'title search', 'search');
    }
    catch (OpenLibraryApiException $e) {
      throw $e;
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
      'limit' => 250,
    ];

    try {
      $response = $this->sendOpenLibraryGet($searchUrl, [
        'query' => $query,
        'timeout' => 5,
        'connect_timeout' => 3,
      ], 'author search', 'search');
    }
    catch (OpenLibraryApiException $e) {
      throw $e;
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

    $loadedEditions = [];
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

      $payloads = $this->loadEditionPayloads($candidateKeys, $debug, $loadedEditions);
      foreach ($candidateKeys as $editionKey) {
        if (!array_key_exists($editionKey, $payloads)) {
          continue;
        }
        $payload = $payloads[$editionKey];
        if (!is_array($payload)) {
          continue;
        }

        $formatInfo = $this->determineFormat($payload);
        $pickedIsbn = NULL;
        if (!empty($payload['isbn_13']) && is_array($payload['isbn_13'])) {
          $candidate = (string) reset($payload['isbn_13']);
          if ($candidate !== '') {
            $pickedIsbn = $candidate;
          }
        }
        if ($pickedIsbn === NULL && !empty($payload['isbn_10']) && is_array($payload['isbn_10'])) {
          $candidate = (string) reset($payload['isbn_10']);
          if ($candidate !== '') {
            $pickedIsbn = $candidate;
          }
        }

        if (is_array($debug)) {
          $entry = $this->createEditionDebugEntry($editionKey, $payload, $formatInfo, FALSE);
          if ($pickedIsbn !== NULL) {
            $entry['picked'] = $pickedIsbn;
            $debug['found_isbn'] = $pickedIsbn;
          }
          $debug['editions'][] = $entry;
        }

        if ($pickedIsbn !== NULL) {
          return $pickedIsbn;
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

    $useCache = !is_array($debug);
    $cacheKey = mb_strtolower($author, 'UTF-8');
    if ($useCache && isset($this->authorCache[$cacheKey])) {
      return $this->authorCache[$cacheKey];
    }

    $searchUrl = 'https://openlibrary.org/search.json';
    $query = [
      'author' => $author,
      'limit' => 250,
    ];

    try {
      $response = $this->sendOpenLibraryGet($searchUrl, [
        'query' => $query,
        'timeout' => 8,
        'connect_timeout' => 4,
      ], 'author search (aggregate)', 'search');
    }
    catch (OpenLibraryApiException $e) {
      throw $e;
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

      $editionRequests = [];
      if ($coverKey !== '') {
        $editionRequests[] = [
          'key' => $coverKey,
          'preferred' => TRUE,
        ];
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
          $editionRequests[] = [
            'key' => $editionKey,
            'preferred' => FALSE,
          ];
        }
      }

      if (!empty($editionRequests)) {
        $orderedKeys = [];
        foreach ($editionRequests as $request) {
          $orderedKeys[] = $request['key'];
        }
        $payloads = $this->loadEditionPayloads($orderedKeys, $debug, $loadedEditions);
        foreach ($editionRequests as $request) {
          $editionKey = $request['key'];
          if (!array_key_exists($editionKey, $payloads)) {
            continue;
          }
          $payload = $payloads[$editionKey];
          if (!is_array($payload)) {
            continue;
          }
          $this->processEditionPayload($editionKey, $payload, $normalizedWork, $results, $sequence, $debug, $request['preferred']);
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
    elseif ($useCache) {
      $this->authorCache[$cacheKey] = $final;
    }
    return $final;
  }

  /**
   * Load edition payloads using shared caching and concurrency helpers.
   *
   * @param string[] $editionKeys
   *   Edition identifiers to fetch.
   *
   * @return array<string, array|false>
   *   Decoded payloads keyed by edition identifier. FALSE indicates a failure.
   *
   * @throws \Drupal\wlt_bookshop\Exception\OpenLibraryApiException
   *   When Open Library responds with a hard-stop status.
   */
  protected function loadEditionPayloads(array $editionKeys, ?array &$debug, array &$loadedEditions): array {
    $results = [];
    if (empty($editionKeys)) {
      return $results;
    }
    $useCache = !is_array($debug);
    $keysToFetch = [];
    $urls = [];

    foreach ($editionKeys as $editionKey) {
      $editionKey = trim($editionKey);
      if ($editionKey === '' || isset($loadedEditions[$editionKey])) {
        continue;
      }
      $loadedEditions[$editionKey] = TRUE;

      if ($useCache && array_key_exists($editionKey, $this->editionCache)) {
        $results[$editionKey] = $this->editionCache[$editionKey];
        continue;
      }

      $keysToFetch[] = $editionKey;
      $urls[$editionKey] = 'https://openlibrary.org/books/' . rawurlencode($editionKey) . '.json';
    }

    if (!empty($keysToFetch)) {
      $promises = [];
      foreach ($keysToFetch as $editionKey) {
        $promises[$editionKey] = $this->sendOpenLibraryGetAsync($urls[$editionKey], [
          'timeout' => 8,
          'connect_timeout' => 4,
        ], 'edition lookup', 'edition');
      }

      $settled = Utils::settle($promises)->wait();
      foreach ($settled as $editionKey => $outcome) {
        $url = $urls[$editionKey];
        if ($outcome['state'] === 'fulfilled') {
          /** @var \Psr\Http\Message\ResponseInterface $response */
          $response = $outcome['value'];
          if ($response->getStatusCode() !== 200) {
            $this->logger->notice('Open Library edition non-200 (@code) for @edition.', [
              '@code' => $response->getStatusCode(),
              '@edition' => $editionKey,
            ]);
            if (is_array($debug)) {
              $debug['editions'][] = [
                'edition' => $editionKey,
                'url' => $url,
                'status' => $response->getStatusCode(),
              ];
            }
            if ($useCache) {
              $this->editionCache[$editionKey] = FALSE;
            }
            $results[$editionKey] = FALSE;
            continue;
          }

          $payload = json_decode((string) $response->getBody(), TRUE);
          if (!is_array($payload)) {
            if (is_array($debug)) {
              $debug['editions'][] = [
                'edition' => $editionKey,
                'url' => $url,
                'decoded' => 'invalid',
              ];
            }
            if ($useCache) {
              $this->editionCache[$editionKey] = FALSE;
            }
            $results[$editionKey] = FALSE;
            continue;
          }

          $results[$editionKey] = $payload;
          if ($useCache) {
            $this->editionCache[$editionKey] = $payload;
          }
        }
        else {
          $reason = $outcome['reason'];
          if ($reason instanceof OpenLibraryApiException) {
            throw $reason;
          }
          $message = $reason instanceof \Throwable ? $reason->getMessage() : 'Unknown error';
          $this->logger->notice('Open Library edition fetch failed for @edition: @message', [
            '@edition' => $editionKey,
            '@message' => $message,
          ]);
          if (is_array($debug)) {
            $debug['editions'][] = [
              'edition' => $editionKey,
              'url' => $url,
              'error' => $message,
            ];
          }
          if ($useCache) {
            $this->editionCache[$editionKey] = FALSE;
          }
          $results[$editionKey] = FALSE;
        }
      }
    }

    return $results;
  }

  /**
   * Append ISBN data from a loaded edition payload.
   */
  protected function processEditionPayload(string $editionKey, array $payload, string $workKey, array &$results, int &$sequence, ?array &$debug, bool $preferred = FALSE): void {
    $normalizedWork = $this->normalizeWorkKey($workKey, $payload['works'] ?? [], $editionKey);
    $formatInfo = $this->determineFormat($payload);

    $isbns = [];
    if (!empty($payload['isbn_13']) && is_array($payload['isbn_13'])) {
      $isbns = array_merge($isbns, array_map('strval', $payload['isbn_13']));
    }
    if (!empty($payload['isbn_10']) && is_array($payload['isbn_10'])) {
      $isbns = array_merge($isbns, array_map('strval', $payload['isbn_10']));
    }

    foreach ($isbns as $isbn) {
      $this->storeIsbnEntry($isbn, $normalizedWork, $formatInfo, $preferred, $results, $sequence);
    }

    if (is_array($debug)) {
      $debug['editions'][] = $this->createEditionDebugEntry($editionKey, $payload, $formatInfo, $preferred);
    }
  }

  /**
   * Build a consistent debug entry for edition payloads.
   */
  protected function createEditionDebugEntry(string $editionKey, array $payload, array $formatInfo, bool $preferred): array {
    $entry = [
      'edition' => $editionKey,
      'url' => 'https://openlibrary.org/books/' . rawurlencode($editionKey) . '.json',
      'format' => $formatInfo['format'],
      'language' => $formatInfo['language'],
      'country' => $formatInfo['country'],
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
    return $entry;
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
    $useCache = !is_array($debug);
    $entries = NULL;
    if ($useCache && array_key_exists($workKey, $this->workEditionsCache)) {
      $cached = $this->workEditionsCache[$workKey];
      if ($cached === FALSE) {
        return;
      }
      $entries = $cached;
    }
    else {
      try {
        $response = $this->sendOpenLibraryGet($url, [
          'timeout' => 10,
          'connect_timeout' => 4,
        ], 'work editions', 'work');
      }
      catch (OpenLibraryApiException $e) {
        throw $e;
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
        elseif ($useCache) {
          $this->workEditionsCache[$workKey] = FALSE;
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
        elseif ($useCache) {
          $this->workEditionsCache[$workKey] = FALSE;
        }
        return;
      }

      $payload = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($payload) || empty($payload['entries']) || !is_array($payload['entries'])) {
        if ($useCache) {
          $this->workEditionsCache[$workKey] = FALSE;
        }
        return;
      }
      $entries = $payload['entries'];
      if ($useCache) {
        $this->workEditionsCache[$workKey] = $entries;
      }
    }

    $normalizedWork = $this->normalizeWorkKey($workKey);
    $editionRequests = [];
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (!empty($entry['key']) && is_string($entry['key'])) {
        $editionKey = ltrim($entry['key'], '/');
        $editionKey = preg_replace('/^books\//', '', $editionKey);
        if ($editionKey !== '' && $editionKey !== $coverKey) {
          $editionRequests[] = $editionKey;
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
      $formatInfo = $this->determineFormat($entry);
      foreach ($isbns as $isbn) {
        $this->storeIsbnEntry($isbn, $normalizedWork, $formatInfo, FALSE, $results, $sequence);
      }
    }

    if (!empty($editionRequests)) {
      $payloads = $this->loadEditionPayloads($editionRequests, $debug, $loadedEditions);
      foreach ($editionRequests as $editionKey) {
        if (!array_key_exists($editionKey, $payloads)) {
          continue;
        }
        $payload = $payloads[$editionKey];
        if (!is_array($payload)) {
          continue;
        }
        $this->processEditionPayload($editionKey, $payload, $normalizedWork, $results, $sequence, $debug, FALSE);
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

  protected function determineFormat(array $data): array {
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
    $format = 'other';
    if ($text !== '') {
      if (str_contains($text, 'paperback') || str_contains($text, 'softcover') || str_contains($text, 'soft cover') || str_contains($text, 'softback') || str_contains($text, 'trade paper')) {
        $format = 'paperback';
      }
      elseif (str_contains($text, 'hardcover') || str_contains($text, 'hardback') || str_contains($text, 'hard cover') || str_contains($text, 'cloth') || str_contains($text, 'library binding')) {
        $format = 'hardcover';
      }
      elseif (str_contains($text, 'audio') || str_contains($text, 'sound recording') || str_contains($text, 'cd') || str_contains($text, 'mp3') || str_contains($text, 'spoken word')) {
        $format = 'audio';
      }
    }

    $languages = [];
    if (!empty($data['languages']) && is_array($data['languages'])) {
      foreach ($data['languages'] as $lang) {
        if (is_array($lang) && !empty($lang['key'])) {
          $languages[] = basename($lang['key']);
        }
        elseif (is_string($lang)) {
          $languages[] = basename($lang);
        }
      }
    }

    $publishPlaces = [];
    if (!empty($data['publish_places']) && is_array($data['publish_places'])) {
      foreach ($data['publish_places'] as $place) {
        if (is_string($place)) {
          $publishPlaces[] = strtolower($place);
        }
        elseif (is_array($place) && !empty($place['name'])) {
          $publishPlaces[] = strtolower($place['name']);
        }
      }
    }

    return [
      'format' => $format,
      'language' => $languages,
      'country' => $publishPlaces,
    ];
  }

  protected function formatScore(array $formatInfo): int {
    $format = $formatInfo['format'] ?? 'other';
    return match ($format) {
      'paperback' => 3,
      'hardcover' => 2,
      'audio' => 1,
      default => 2,
    };
  }

  protected function languageScore(array $formatInfo): int {
    $langs = array_map('strtolower', $formatInfo['language'] ?? []);
    if (in_array('eng', $langs, TRUE)) {
      return 2;
    }
    if (!empty($langs)) {
      return 1;
    }
    return 0;
  }

  protected function countryScore(array $formatInfo): int {
    $places = $formatInfo['country'] ?? [];
    foreach ($places as $place) {
      if (str_contains($place, 'united states') || str_contains($place, 'u.s.') || str_contains($place, 'usa')) {
        return 2;
      }
      if (str_contains($place, 'london') || str_contains($place, 'united kingdom') || str_contains($place, 'uk') || str_contains($place, 'england')) {
        return 2;
      }
    }
    if (!empty($places)) {
      return 1;
    }
    return 0;
  }

  protected function storeIsbnEntry(string $isbn, string $workKey, array $formatInfo, bool $preferred, array &$results, int &$sequence): void {
    $normalized = preg_replace('/[^0-9X]/i', '', $isbn);
    if ($normalized === '') {
      return;
    }
    $entry = [
      'isbn' => $normalized,
      'score' => $this->formatScore($formatInfo),
      'langScore' => $this->languageScore($formatInfo),
      'countryScore' => $this->countryScore($formatInfo),
      'order' => $sequence++,
      'preferred' => $preferred,
      'format' => $formatInfo['format'] ?? 'other',
      'work' => $workKey,
    ];

    if (!isset($results[$normalized])) {
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
    if ($candidate['langScore'] > $existing['langScore']) {
      return TRUE;
    }
    if ($candidate['langScore'] < $existing['langScore']) {
      return FALSE;
    }
    if ($candidate['countryScore'] > $existing['countryScore']) {
      return TRUE;
    }
    if ($candidate['countryScore'] < $existing['countryScore']) {
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
      $maxLang = max(array_column($entries, 'langScore'));
      $entries = array_filter($entries, static fn($entry) => $entry['langScore'] >= $maxLang);
      $maxCountry = max(array_column($entries, 'countryScore'));
      $entries = array_filter($entries, static fn($entry) => $entry['countryScore'] >= $maxCountry);
      uasort($entries, static function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
          return $b['score'] <=> $a['score'];
        }
        if ($a['langScore'] !== $b['langScore']) {
          return $b['langScore'] <=> $a['langScore'];
        }
        if ($a['countryScore'] !== $b['countryScore']) {
          return $b['countryScore'] <=> $a['countryScore'];
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
