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
  private const OPEN_LIBRARY_RATE_LIMIT = 10; 
  private const OPEN_LIBRARY_WINDOW_SECONDS = 3; 

  /** @var \GuzzleHttp\ClientInterface */
  protected $httpClient;

  /** @var \Psr\Log\LoggerInterface */
  protected $logger;

  /**
   * Cached author lookups (full entry metadata) for the current request when
   * debug output is disabled.
   *
   * @var array<string, array>
   *   keyed by lowercased author name.
   */
  protected array $authorEntryCache = [];

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
   * Whether verbose logging of API requests is enabled.
   */
  protected bool $verboseLogging = FALSE;

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

  /**
   * When TRUE, edition/work lookups are skipped in favor of search doc data.
   */
  protected bool $skipEditionLookups = FALSE;

  /**
   * Local work index path and SQLite handle.
   */
  protected ?string $workIndexPath = NULL;

  /** @var \SQLite3|null */
  protected $workIndexDb = NULL;

  /**
   * Local edition offset index path and SQLite handle.
   */
  protected ?string $editionOffsetIndexPath = NULL;

  /** @var \SQLite3|null */
  protected $editionOffsetDb = NULL;

  /**
   * Local edition dump path and active handle.
   */
  protected ?string $editionDumpPath = NULL;

  /** @var resource|null */
  protected $editionDumpHandle = NULL;

  public function __construct(ClientInterface $http_client, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  public function __destruct() {
    if (is_resource($this->editionDumpHandle)) {
      fclose($this->editionDumpHandle);
    }
    if ($this->workIndexDb instanceof \SQLite3) {
      $this->workIndexDb->close();
    }
    if ($this->editionOffsetDb instanceof \SQLite3) {
      $this->editionOffsetDb->close();
    }
  }

  /**
   * Toggle verbose API logging.
   */
  public function setVerboseLogging(bool $enabled): void {
    $this->verboseLogging = $enabled;
  }

  /**
   * Retrieve current API call statistics.
   */
  public function getApiStats(): array {
    return $this->apiStats;
  }

  /**
   * Enable or disable edition/work lookups.
   */
  public function setSkipEditionLookups(bool $enabled): void {
    $this->skipEditionLookups = $enabled;
  }

  /**
    * Set the local work index path.
    */
  public function setWorkIndexPath(?string $path): void {
    $path = $path !== NULL ? trim($path) : '';
    $this->workIndexPath = $path !== '' ? $path : NULL;
    if ($this->workIndexDb instanceof \SQLite3) {
      $this->workIndexDb->close();
      $this->workIndexDb = NULL;
    }
  }

  /**
   * Set the local edition offset index path.
   */
  public function setEditionOffsetIndexPath(?string $path): void {
    $path = $path !== NULL ? trim($path) : '';
    $this->editionOffsetIndexPath = $path !== '' ? $path : NULL;
    if ($this->editionOffsetDb instanceof \SQLite3) {
      $this->editionOffsetDb->close();
      $this->editionOffsetDb = NULL;
    }
  }

  /**
   * Set the local edition dump path.
   */
  public function setEditionDumpPath(?string $path): void {
    $path = $path !== NULL ? trim($path) : '';
    $normalized = $path !== '' ? $path : NULL;
    if ($this->editionDumpPath !== $normalized && is_resource($this->editionDumpHandle)) {
      fclose($this->editionDumpHandle);
      $this->editionDumpHandle = NULL;
    }
    $this->editionDumpPath = $normalized;
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

  protected function getCacheDirectory(): string {
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wlt_bookshop_cache';
    if (!is_dir($base)) {
      @mkdir($base, 0777, TRUE);
    }
    return $base;
  }

  protected function getCachedIndexPath(string $type, string $sourcePath): ?string {
    if (!is_file($sourcePath)) {
      $this->logger->error('Index source @path is not a file.', ['@path' => $sourcePath]);
      return NULL;
    }
    $real = realpath($sourcePath) ?: $sourcePath;
    $hash = hash('sha256', implode('|', [
      $type,
      $real,
      (string) @filesize($sourcePath),
      (string) @filemtime($sourcePath),
    ]));
    return $this->getCacheDirectory() . DIRECTORY_SEPARATOR . $type . '_' . $hash . '.sqlite';
  }

  protected function ensureWorkIndexDb(): void {
    if (!class_exists(\SQLite3::class)) {
      $this->logger->error('SQLite3 extension is required for local work index support.');
      $this->workIndexPath = NULL;
      return;
    }
    if ($this->workIndexDb instanceof \SQLite3 || $this->workIndexPath === NULL) {
      return;
    }
    $cachePath = $this->getCachedIndexPath('work_index', $this->workIndexPath);
    if ($cachePath === NULL) {
      return;
    }
    if (!file_exists($cachePath)) {
      $this->buildWorkIndexDatabase($this->workIndexPath, $cachePath);
    }
    if (file_exists($cachePath)) {
      $this->workIndexDb = new \SQLite3($cachePath, SQLITE3_OPEN_READONLY);
    }
  }

  protected function ensureEditionOffsetDb(): void {
    if (!class_exists(\SQLite3::class)) {
      $this->logger->error('SQLite3 extension is required for local edition offset support.');
      $this->editionOffsetIndexPath = NULL;
      return;
    }
    if ($this->editionOffsetDb instanceof \SQLite3 || $this->editionOffsetIndexPath === NULL) {
      return;
    }
    $cachePath = $this->getCachedIndexPath('edition_offsets', $this->editionOffsetIndexPath);
    if ($cachePath === NULL) {
      return;
    }
    if (!file_exists($cachePath)) {
      $this->buildEditionOffsetDatabase($this->editionOffsetIndexPath, $cachePath);
    }
    if (file_exists($cachePath)) {
      $this->editionOffsetDb = new \SQLite3($cachePath, SQLITE3_OPEN_READONLY);
    }
  }

  protected function buildWorkIndexDatabase(string $sourcePath, string $destination): void {
    $lock = $destination . '.lock';
    $lockHandle = @fopen($lock, 'c');
    if (is_resource($lockHandle)) {
      flock($lockHandle, LOCK_EX);
    }
    try {
      if (file_exists($destination)) {
        return;
      }
      $this->logger->notice('Building work index cache at @dest from @src', [
        '@dest' => $destination,
        '@src' => $sourcePath,
      ]);
      $temp = $destination . '.tmp_' . getmypid();
      if (file_exists($temp)) {
        @unlink($temp);
      }
      $db = new \SQLite3($temp);
      $db->exec('PRAGMA journal_mode = OFF;');
      $db->exec('PRAGMA synchronous = OFF;');
      $db->exec('PRAGMA temp_store = MEMORY;');
      $db->exec('CREATE TABLE work_index (work TEXT NOT NULL, edition TEXT NOT NULL);');
      $db->exec('CREATE INDEX work_index_work_idx ON work_index(work);');
      $insert = $db->prepare('INSERT INTO work_index (work, edition) VALUES (:work, :edition)');
      $handle = @fopen($sourcePath, 'rb');
      if ($handle === FALSE) {
        throw new \RuntimeException(sprintf('Unable to open work index source %s', $sourcePath));
      }
      $db->exec('BEGIN TRANSACTION');
      $count = 0;
      while (($line = fgets($handle)) !== FALSE) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
          continue;
        }
        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) < 2) {
          continue;
        }
        $workKey = trim($parts[0]);
        if ($workKey === '') {
          continue;
        }
        $editionKey = $this->normalizeEditionKey($parts[1]);
        if ($editionKey === '') {
          continue;
        }
        $insert->bindValue(':work', $workKey, SQLITE3_TEXT);
        $insert->bindValue(':edition', $editionKey, SQLITE3_TEXT);
        $insert->execute();
        $count++;
        if ($count % 5000 === 0) {
          $db->exec('COMMIT');
          $db->exec('BEGIN TRANSACTION');
        }
      }
      $db->exec('COMMIT');
      fclose($handle);
      $db->close();
      if (!@rename($temp, $destination)) {
        $this->logger->error('Failed moving work index cache into place (@temp -> @dest).', [
          '@temp' => $temp,
          '@dest' => $destination,
        ]);
        @unlink($temp);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed building work index cache: @message', ['@message' => $e->getMessage()]);
      if (isset($temp) && file_exists($temp)) {
        @unlink($temp);
      }
    }
    finally {
      if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lock);
      }
    }
  }

  protected function buildEditionOffsetDatabase(string $sourcePath, string $destination): void {
    $lock = $destination . '.lock';
    $lockHandle = @fopen($lock, 'c');
    if (is_resource($lockHandle)) {
      flock($lockHandle, LOCK_EX);
    }
    try {
      if (file_exists($destination)) {
        return;
      }
      $this->logger->notice('Building edition offset cache at @dest from @src', [
        '@dest' => $destination,
        '@src' => $sourcePath,
      ]);
      $temp = $destination . '.tmp_' . getmypid();
      if (file_exists($temp)) {
        @unlink($temp);
      }
      $db = new \SQLite3($temp);
      $db->exec('PRAGMA journal_mode = OFF;');
      $db->exec('PRAGMA synchronous = OFF;');
      $db->exec('PRAGMA temp_store = MEMORY;');
      $db->exec('CREATE TABLE edition_offsets (edition TEXT PRIMARY KEY, offset INTEGER NOT NULL);');
      $insert = $db->prepare('INSERT INTO edition_offsets (edition, offset) VALUES (:edition, :offset)');
      $handle = @fopen($sourcePath, 'rb');
      if ($handle === FALSE) {
        throw new \RuntimeException(sprintf('Unable to open edition offset source %s', $sourcePath));
      }
      $db->exec('BEGIN TRANSACTION');
      $count = 0;
      while (($line = fgets($handle)) !== FALSE) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
          continue;
        }
        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) < 2) {
          continue;
        }
        $editionKey = $this->normalizeEditionKey($parts[0]);
        if ($editionKey === '') {
          continue;
        }
        $offset = (int) $parts[1];
        $insert->bindValue(':edition', $editionKey, SQLITE3_TEXT);
        $insert->bindValue(':offset', $offset, SQLITE3_INTEGER);
        $insert->execute();
        $count++;
        if ($count % 5000 === 0) {
          $db->exec('COMMIT');
          $db->exec('BEGIN TRANSACTION');
        }
      }
      $db->exec('COMMIT');
      fclose($handle);
      $db->close();
      if (!@rename($temp, $destination)) {
        $this->logger->error('Failed moving edition offset cache into place (@temp -> @dest).', [
          '@temp' => $temp,
          '@dest' => $destination,
        ]);
        @unlink($temp);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed building edition offset cache: @message', ['@message' => $e->getMessage()]);
      if (isset($temp) && file_exists($temp)) {
        @unlink($temp);
      }
    }
    finally {
      if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lock);
      }
    }
  }

  protected function hasLocalEditionData(): bool {
    $this->ensureEditionOffsetDb();
    return $this->editionDumpPath !== NULL && $this->editionOffsetDb instanceof \SQLite3;
  }

  protected function shouldUseWorkIndex(): bool {
    if (!$this->hasLocalEditionData()) {
      return FALSE;
    }
    $this->ensureWorkIndexDb();
    return $this->workIndexDb instanceof \SQLite3;
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
   * Log a verbose message when enabled.
   */
  protected function logVerbose(string $message, array $context = []): void {
    if (!$this->verboseLogging) {
      return;
    }
    if (PHP_SAPI === 'cli') {
      $formatted = '[Open Library] ' . $message;
      if (!empty($context)) {
        $formatted .= ' ' . json_encode($context);
      }
      // phpcs:ignore DrupalPractice.General.AccessGlobals.Sysprint
      print $formatted . PHP_EOL;
    }
    else {
      $this->logger->notice('[Open Library] ' . $message, $context);
    }
  }

  /**
   * Summarize a response body for verbose logging.
   */
  protected function summarizeBody(ResponseInterface $response, int $limit = 500): string {
    $body = (string) $response->getBody();
    if (strlen($body) > $limit) {
      $body = substr($body, 0, $limit) . '…';
    }
    return $body;
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
    $this->logVerbose(sprintf('Request [%s] %s %s', $category, $context, $url), [
      'query' => $options['query'] ?? [],
    ]);
    $response = $this->httpClient->request('GET', $url, $options);
    $this->guardOpenLibraryResponse($url, $response, $context);
    $this->logVerbose(sprintf('Response [%s] %s status=%d', $category, $url, $response->getStatusCode()), [
      'body' => $this->summarizeBody($response),
    ]);
    return $response;
  }

  /**
   * Queue a throttled Open Library GET request asynchronously.
   */
  protected function sendOpenLibraryGetAsync(string $url, array $options, string $context, string $category = 'other'): PromiseInterface {
    $options = $this->prepareOpenLibraryOptions($options);
    $this->throttleOpenLibraryRequests();
    $this->incrementApiStat($category);
    $this->logVerbose(sprintf('Async request [%s] %s %s', $category, $context, $url), [
      'query' => $options['query'] ?? [],
    ]);
    return $this->httpClient->requestAsync('GET', $url, $options)
      ->then(function (ResponseInterface $response) use ($url, $context, $category) {
        $this->guardOpenLibraryResponse($url, $response, $context);
        $this->logVerbose(sprintf('Async response [%s] %s status=%d', $category, $url, $response->getStatusCode()), [
          'body' => $this->summarizeBody($response),
        ]);
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
      'q' => 'title:' . $title,
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
      'q' => 'author:' . $author,
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

    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
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

    if ($this->skipEditionLookups) {
      foreach ($data['docs'] as $doc) {
        if (empty($doc['isbn']) || !is_array($doc['isbn'])) {
          continue;
        }
        foreach ($doc['isbn'] as $candidate) {
          $normalized = preg_replace('/[^0-9X]/i', '', (string) $candidate);
          if ($normalized !== '') {
            return $normalized;
          }
        }
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
      $candidateKeys = $this->extractEditionKeysFromDoc((array) $doc);
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
          $source = $payload['_source'] ?? 'api';
          $entry = $this->createEditionDebugEntry($editionKey, $payload, $formatInfo, FALSE, $source);
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
   * @return array<int, array>
   *   A de-duplicated list of ISBN metadata arrays, ordered by relevance.
   */
  public function getIsbnEntriesByAuthor(string $author, ?array &$debug = NULL): array {
    $author = trim($author);
    if ($author === '') {
      return [];
    }

    $useCache = !is_array($debug) && !$this->skipEditionLookups;
    $cacheKey = mb_strtolower($author, 'UTF-8');
    if ($useCache && isset($this->authorEntryCache[$cacheKey])) {
      return $this->authorEntryCache[$cacheKey];
    }

    $searchUrl = 'https://openlibrary.org/search.json';
    $query = [
      'q' => 'author:' . $author,
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

    if (is_array($debug)) {
      $debug['search']['response_sample'] = $this->summarizeSearchDocs($data['docs']);
      $debug['search']['raw_bytes'] = strlen($body);
    }

    if ($this->skipEditionLookups) {
      $entries = $this->buildSearchDocIsbnEntries($data['docs']);
      if (empty($entries) && is_array($debug) && isset($debug['title_fallback'])) {
        $entries = $this->buildSearchDocIsbnEntries($debug['title_fallback']['docs'] ?? []);
      }
      if (is_array($debug)) {
        $debug['search_only'] = TRUE;
        $debug['found_isbns'] = array_map(static fn(array $entry) => $entry['isbn'], $entries);
      }
      return $entries;
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
      $workTitle = $this->buildEditionTitle($doc);
      $workContext = [
        'work_title' => $workTitle,
      ];
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
          $this->processEditionPayload($editionKey, $payload, $normalizedWork, $results, $sequence, $debug, $request['preferred'], $workContext);
        }
      }

      if ($workKey !== '') {
        $this->appendWorkEditionIsbns($workKey, $coverKey, $results, $sequence, $debug, $loadedEditions, $workContext);
      }
    }

    $finalEntries = $this->finalizeIsbnEntries($results);
    if (is_array($debug)) {
      $debug['found_isbns'] = array_map(static fn(array $entry) => $entry['isbn'], $finalEntries);
    }
    elseif ($useCache) {
      $this->authorEntryCache[$cacheKey] = $finalEntries;
    }
    return $finalEntries;
  }

  /**
   * Backwards-compatible wrapper returning just the ordered ISBN strings.
   *
   * @return string[]
   *   Unique ISBN values ordered by relevance.
   */
  public function getIsbnsByAuthor(string $author, ?array &$debug = NULL): array {
    $entries = $this->getIsbnEntriesByAuthor($author, $debug);
    if (empty($entries)) {
      return [];
    }
    return array_values(array_map(static fn(array $entry) => $entry['isbn'], $entries));
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
    $useLocal = $this->hasLocalEditionData();
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
      if ($useLocal) {
        foreach ($keysToFetch as $editionKey) {
          $payload = $this->loadEditionPayloadFromFile($editionKey, $debug);
          if ($payload === FALSE) {
            if ($useCache) {
              $this->editionCache[$editionKey] = FALSE;
            }
            $results[$editionKey] = FALSE;
            if (is_array($debug)) {
              $debug['editions'][] = [
                'edition' => $editionKey,
                'source' => 'local',
                'error' => 'missing_or_invalid',
              ];
            }
            continue;
          }
          if (is_array($payload)) {
            $payload['_source'] = 'dump';
          }
          $results[$editionKey] = $payload;
          if ($useCache) {
            $this->editionCache[$editionKey] = $payload;
          }
        }
        return $results;
      }

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

          if (is_array($payload)) {
            $payload['_source'] = 'api';
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
   * Retrieve ISBN entries by searching with a work/title query.
   */
  protected function collectIsbnEntriesByTitle(string $title, ?array &$debug = NULL): array {
    $title = trim($title);
    if ($title === '') {
      return [];
    }
    $query = [
      'q' => 'title:' . $title,
      'limit' => 50,
    ];
    $url = 'https://openlibrary.org/search.json';
    try {
      $response = $this->sendOpenLibraryGet($url, [
        'query' => $query,
        'timeout' => 8,
        'connect_timeout' => 4,
      ], 'title fallback search', 'search');
    }
    catch (OpenLibraryApiException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open Library title search failed for \"@title\": @message', [
        '@title' => $title,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('Open Library title search non-200 (@code) for \"@title\".', [
        '@code' => $response->getStatusCode(),
        '@title' => $title,
      ]);
      return [];
    }
    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if (!is_array($data) || empty($data['docs'])) {
      return [];
    }
    if (is_array($debug)) {
      $debug['title_fallback'] = [
        'title' => $title,
        'docs' => $data['docs'],
        'response_sample' => $this->summarizeSearchDocs($data['docs']),
        'raw_bytes' => strlen($body),
      ];
    }
    $entries = $this->buildSearchDocIsbnEntries($data['docs']);
    return $this->finalizeIsbnEntries(array_map(static function ($isbn) use ($title) {
      return [
        'isbn' => $isbn,
        'score' => 5,
        'langScore' => 5,
        'countryScore' => 5,
        'order' => 0,
        'preferred' => TRUE,
        'format' => 'title-match',
        'work' => '_title_fallback',
        'title' => $title,
        'work_title' => $title,
      ];
    }, array_map(static fn(array $entry) => $entry['isbn'], $entries)));
  }

  /**
   * Append ISBN data from a loaded edition payload.
   */
  protected function processEditionPayload(string $editionKey, array $payload, string $workKey, array &$results, int &$sequence, ?array &$debug, bool $preferred = FALSE, array $context = []): void {
    $normalizedWork = $this->normalizeWorkKey($workKey, $payload['works'] ?? [], $editionKey);
    $formatInfo = $this->determineFormat($payload);
    $workTitle = $context['work_title'] ?? '';
    $editionTitle = $this->buildEditionTitle($payload, $workTitle);
    $entryContext = [
      'title' => $editionTitle,
      'work_title' => $workTitle,
    ];

    $isbns = [];
    if (!empty($payload['isbn_13']) && is_array($payload['isbn_13'])) {
      $isbns = array_merge($isbns, array_map('strval', $payload['isbn_13']));
    }
    if (!empty($payload['isbn_10']) && is_array($payload['isbn_10'])) {
      $isbns = array_merge($isbns, array_map('strval', $payload['isbn_10']));
    }

    foreach ($isbns as $isbn) {
      $this->storeIsbnEntry($isbn, $normalizedWork, $formatInfo, $preferred, $results, $sequence, $entryContext);
    }

    if (is_array($debug)) {
      $source = $payload['_source'] ?? 'api';
      $debug['editions'][] = $this->createEditionDebugEntry($editionKey, $payload, $formatInfo, $preferred, $source);
    }
  }

  /**
   * Build a consistent debug entry for edition payloads.
   */
  protected function createEditionDebugEntry(string $editionKey, array $payload, array $formatInfo, bool $preferred, string $source = 'api'): array {
    $entry = [
      'edition' => $editionKey,
      'url' => 'https://openlibrary.org/books/' . rawurlencode($editionKey) . '.json',
      'format' => $formatInfo['format'],
      'language' => $formatInfo['language'],
      'country' => $formatInfo['country'],
      'source' => $source,
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
  protected function appendWorkEditionIsbns(string $workKey, string $coverKey, array &$results, int &$sequence, ?array &$debug, array &$loadedEditions, array $context = []): void {
    $workKey = trim($workKey);
    if ($workKey === '') {
      return;
    }
    if ($this->shouldUseWorkIndex()) {
      $editionRequests = $this->getEditionKeysForWork($workKey);
      if (!empty($editionRequests)) {
        if (is_array($debug)) {
          $debug['work_index'][] = [
            'work' => $workKey,
            'editions' => $editionRequests,
            'source' => 'sqlite',
          ];
        }
        $payloads = $this->loadEditionPayloads($editionRequests, $debug, $loadedEditions);
        foreach ($editionRequests as $editionKey) {
          if (empty($payloads[$editionKey]) || !is_array($payloads[$editionKey])) {
            continue;
          }
          $preferred = ($coverKey !== '' && $editionKey === $coverKey);
          $this->processEditionPayload($editionKey, $payloads[$editionKey], $workKey, $results, $sequence, $debug, $preferred, $context);
        }
        return;
      }
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
      if (is_array($debug)) {
        $debug['works'][] = [
          'work' => $workKey,
          'url' => $url,
          'entries' => count($entries),
        ];
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
      $editionTitle = $this->buildEditionTitle($entry, $context['work_title'] ?? '');
      $entryContext = [
        'title' => $editionTitle,
        'work_title' => $context['work_title'] ?? '',
      ];
      $formatInfo = $this->determineFormat($entry);
      foreach ($isbns as $isbn) {
        $this->storeIsbnEntry($isbn, $normalizedWork, $formatInfo, FALSE, $results, $sequence, $entryContext);
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
        $this->processEditionPayload($editionKey, $payload, $normalizedWork, $results, $sequence, $debug, FALSE, $context);
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

  /**
   * Build a human-readable title string from Open Library payload data.
   */
  protected function buildEditionTitle(array $data, string $fallback = ''): string {
    $title = '';
    if (!empty($data['title']) && is_string($data['title'])) {
      $title = trim((string) $data['title']);
    }
    $subtitle = '';
    if (!empty($data['subtitle']) && is_string($data['subtitle'])) {
      $subtitle = trim((string) $data['subtitle']);
    }
    if ($title === '' && $fallback !== '') {
      $title = $fallback;
    }
    if ($title === '' && $subtitle !== '') {
      $title = $subtitle;
      $subtitle = '';
    }
    $combined = $title;
    if ($subtitle !== '') {
      $combined = $combined !== '' ? $combined . ': ' . $subtitle : $subtitle;
    }
    if ($combined === '' && !empty($data['edition_name']) && is_string($data['edition_name'])) {
      $combined = trim((string) $data['edition_name']);
    }
    return $combined;
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

  protected function storeIsbnEntry(string $isbn, string $workKey, array $formatInfo, bool $preferred, array &$results, int &$sequence, array $context = []): void {
    $normalized = preg_replace('/[^0-9X]/i', '', $isbn);
    if ($normalized === '') {
      return;
    }
    $workTitle = $context['work_title'] ?? '';
    $editionTitle = $context['title'] ?? $workTitle;
    $entry = [
      'isbn' => $normalized,
      'score' => $this->formatScore($formatInfo),
      'langScore' => $this->languageScore($formatInfo),
      'countryScore' => $this->countryScore($formatInfo),
      'order' => $sequence++,
      'preferred' => $preferred,
      'format' => $formatInfo['format'] ?? 'other',
      'work' => $workKey,
      'title' => $editionTitle,
      'work_title' => $workTitle,
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

  protected function finalizeIsbnEntries(array $results): array {
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
        $final[] = $info;
      }
    }

    return $final;
  }

  protected function finalizeIsbnResults(array $results): array {
    $entries = $this->finalizeIsbnEntries($results);
    if (empty($entries)) {
      return [];
    }
    return array_values(array_map(static fn(array $entry) => $entry['isbn'], $entries));
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
   * Build ISBN entries directly from search documents.
   *
   * @param array $docs
   *   Search results containing potential ISBN arrays.
   *
   * @return array<int, array>
   *   Ordered ISBN entries.
   */
  protected function buildSearchDocIsbnEntries(array $docs): array {
    if (empty($docs)) {
      return [];
    }
    $results = [];
    $sequence = 0;
    foreach ($docs as $doc) {
      if (empty($doc['isbn']) || !is_array($doc['isbn'])) {
        continue;
      }
      $title = '';
      if (!empty($doc['title'])) {
        $title = (string) $doc['title'];
      }
      elseif (!empty($doc['title_suggest'])) {
        $title = (string) $doc['title_suggest'];
      }
      foreach ($doc['isbn'] as $candidate) {
        $normalized = preg_replace('/[^0-9X]/i', '', (string) $candidate);
        if ($normalized === '') {
          continue;
        }
        if (!isset($results[$normalized])) {
          $results[$normalized] = [
            'isbn' => $normalized,
            'title' => $title,
            'work_title' => $title,
            'preferred' => FALSE,
            'score' => 0,
            'langScore' => 0,
            'countryScore' => 0,
            'order' => $sequence++,
            'format' => 'other',
            'work' => '',
          ];
        }
      }
    }
    if (empty($results)) {
      return [];
    }
    uasort($results, static function (array $a, array $b): int {
      return $a['order'] <=> $b['order'];
    });
    return array_values($results);
  }

  /**
   * Produce a lightweight summary of Open Library search docs.
   *
   * @param array $docs
   *   Raw documents from search.json.
   * @param int $limit
   *   Maximum number of docs to include.
   *
   * @return array<int, array<string, mixed>>
   *   Summaries containing title, key, and counts.
   */
  protected function summarizeSearchDocs(array $docs, int $limit = 5): array {
    $summaries = [];
    $count = 0;
    foreach ($docs as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $summary = [
        'title' => isset($doc['title']) ? (string) $doc['title'] : '',
        'key' => isset($doc['key']) ? (string) $doc['key'] : '',
        'cover_edition_key' => isset($doc['cover_edition_key']) ? (string) $doc['cover_edition_key'] : '',
        'isbn_count' => !empty($doc['isbn']) && is_array($doc['isbn']) ? count($doc['isbn']) : 0,
      ];
      $summaries[] = $summary;
      if (++$count >= $limit) {
        break;
      }
    }
    return $summaries;
  }

  /**
   * Split a title with a trailing "by …" segment into work/byline parts.
   */
  protected function splitTitleByline(string $title): array {
    $title = trim($title);
    if ($title === '') {
      return ['work' => '', 'byline' => ''];
    }
    $lower = mb_strtolower($title);
    $pos = mb_strripos($lower, ' by ');
    if ($pos === FALSE) {
      return ['work' => '', 'byline' => ''];
    }
    $work = trim(mb_substr($title, 0, $pos));
    $byline = trim(mb_substr($title, $pos + 4));
    return [
      'work' => $work,
      'byline' => $byline,
    ];
  }

  /**
   * Attempt to find ISBN entries using title-based heuristics.
   */
  public function getTitleFallbackEntries(string $title, ?array &$debug = NULL): array {
    $title = trim($title);
    if ($title === '') {
      return [];
    }
    $parts = $this->splitTitleByline($title);
    if ($parts['byline'] !== '') {
      $entries = $this->getIsbnEntriesByAuthor($parts['byline'], $debug);
      if (!empty($entries)) {
        foreach ($entries as &$entry) {
          $entry['fallback_priority'] = -5;
        }
        unset($entry);
        return $entries;
      }
    }
    if ($parts['work'] !== '') {
      $entries = $this->collectTitleMatchEntries($parts['work'], 'title_work', $debug);
      if (!empty($entries)) {
        return $entries;
      }
    }
    return $this->collectTitleMatchEntries($title, 'title_full', $debug);
  }

  /**
   * Collect entries by searching Open Library for a title string.
   */
  protected function collectTitleMatchEntries(string $title, string $mode, ?array &$debug = NULL): array {
    $title = trim($title);
    if ($title === '') {
      return [];
    }
    $query = [
      'q' => 'title:' . $title,
      'limit' => 50,
    ];
    $url = 'https://openlibrary.org/search.json';
    try {
      $response = $this->sendOpenLibraryGet($url, [
        'query' => $query,
        'timeout' => 8,
        'connect_timeout' => 4,
      ], 'title fallback search', 'search');
    }
    catch (OpenLibraryApiException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open Library title search failed for \"@title\": @message', [
        '@title' => $title,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('Open Library title search non-200 (@code) for \"@title\".', [
        '@code' => $response->getStatusCode(),
        '@title' => $title,
      ]);
      return [];
    }
    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if (!is_array($data) || empty($data['docs']) || !is_array($data['docs'])) {
      return [];
    }
    if (is_array($debug)) {
      $debug['title_query'][] = [
        'mode' => $mode,
        'title' => $title,
        'num_docs' => isset($data['numFound']) ? (int) $data['numFound'] : count($data['docs']),
        'response_sample' => $this->summarizeSearchDocs($data['docs']),
        'raw_bytes' => strlen($body),
      ];
    }
    $entries = [];
    $discovery = 0;
    $docsRequiringEditions = [];
    $fallbackPriority = $mode === 'title_work' ? -20 : -15;

    foreach ($data['docs'] as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      if (!empty($doc['isbn']) && is_array($doc['isbn'])) {
        $docTitle = isset($doc['title']) ? (string) $doc['title'] : $title;
        foreach ($doc['isbn'] as $isbn) {
          $entries[] = [
            'isbn' => (string) $isbn,
            'source' => $mode,
            'title' => $docTitle,
            'work_title' => $docTitle,
            'preferred' => TRUE,
            'discovery' => $discovery++,
            'fallback_priority' => $fallbackPriority,
          ];
        }
        continue;
      }
      $docsRequiringEditions[] = $doc;
    }

    if (!$this->skipEditionLookups && !empty($docsRequiringEditions)) {
      $loadedEditions = [];
      $editionResults = [];
      $sequence = 0;
      foreach ($docsRequiringEditions as $doc) {
        $workKey = isset($doc['key']) ? (string) $doc['key'] : '';
        $workTitle = $this->buildEditionTitle($doc);
        if ($workTitle === '') {
          $workTitle = isset($doc['title']) ? (string) $doc['title'] : $title;
        }
        $workContext = [
          'work_title' => $workTitle,
        ];
        $coverKey = '';
        if (!empty($doc['cover_edition_key'])) {
          $coverKey = $this->normalizeEditionKey((string) $doc['cover_edition_key']);
        }
        $candidateKeys = $this->extractEditionKeysFromDoc($doc);
        $editionRequests = [];
        foreach ($candidateKeys as $candidate) {
          $editionRequests[] = [
            'key' => $candidate,
            'preferred' => ($coverKey !== '' && $candidate === $coverKey),
          ];
        }
        if (!empty($editionRequests)) {
          $orderedKeys = array_map(static fn(array $request) => $request['key'], $editionRequests);
          $payloads = $this->loadEditionPayloads($orderedKeys, $debug, $loadedEditions);
          foreach ($editionRequests as $request) {
            $editionKey = $request['key'];
            if (!isset($payloads[$editionKey]) || !is_array($payloads[$editionKey])) {
              continue;
            }
            $this->processEditionPayload($editionKey, $payloads[$editionKey], $workKey, $editionResults, $sequence, $debug, $request['preferred'], $workContext);
          }
        }
        if ($workKey !== '') {
          $this->appendWorkEditionIsbns($workKey, $coverKey, $editionResults, $sequence, $debug, $loadedEditions, $workContext);
        }
      }

      $finalized = $this->finalizeIsbnEntries($editionResults);
      foreach ($finalized as $info) {
        $entries[] = [
          'isbn' => $info['isbn'],
          'source' => $mode,
          'title' => $info['title'] ?? ($info['work_title'] ?? $title),
          'work_title' => $info['work_title'] ?? ($info['title'] ?? $title),
          'preferred' => !empty($info['preferred']),
          'discovery' => $discovery++,
          'fallback_priority' => $fallbackPriority,
        ];
      }
    }

    return $entries;
  }

  /**
   * Extract candidate edition keys from a search document.
   */
  protected function extractEditionKeysFromDoc(array $doc): array {
    $keys = [];
    if (!empty($doc['cover_edition_key'])) {
      $keys[] = (string) $doc['cover_edition_key'];
    }
    if (!empty($doc['edition_key'])) {
      if (is_array($doc['edition_key'])) {
        foreach ($doc['edition_key'] as $editionKey) {
          $editionKey = (string) $editionKey;
          if ($editionKey !== '') {
            $keys[] = $editionKey;
          }
        }
      }
      elseif (is_string($doc['edition_key'])) {
        $keys[] = $doc['edition_key'];
      }
    }
    if (empty($keys)) {
      return [];
    }
    $normalized = [];
    foreach ($keys as $key) {
      $value = $this->normalizeEditionKey($key);
      if ($value !== '') {
        $normalized[$value] = TRUE;
      }
    }
    return array_keys($normalized);
  }

  protected function normalizeEditionKey(string $key): string {
    $key = trim($key);
    if ($key === '') {
      return '';
    }
    $key = ltrim($key, '/');
    if (str_starts_with($key, 'books/')) {
      $key = substr($key, 6);
    }
    return $key;
  }

  protected function loadWorkIndexMap(): void {
    $this->ensureWorkIndexDb();
  }

  protected function getEditionKeysForWork(string $workKey): array {
    $this->ensureWorkIndexDb();
    if (!$this->workIndexDb instanceof \SQLite3) {
      return [];
    }
    $stmt = $this->workIndexDb->prepare('SELECT edition FROM work_index WHERE work = :work');
    $stmt->bindValue(':work', $workKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
      return [];
    }
    $editions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      if (!empty($row['edition'])) {
        $editions[] = $row['edition'];
      }
    }
    $result->finalize();
    return $editions;
  }

  protected function loadEditionOffsetIndex(): void {
    $this->ensureEditionOffsetDb();
  }

  protected function getEditionOffset(string $editionKey): ?int {
    $this->ensureEditionOffsetDb();
    if (!$this->editionOffsetDb instanceof \SQLite3) {
      return NULL;
    }
    $normalized = $this->normalizeEditionKey($editionKey);
    if ($normalized === '') {
      return NULL;
    }
    $stmt = $this->editionOffsetDb->prepare('SELECT offset FROM edition_offsets WHERE edition = :edition');
    $stmt->bindValue(':edition', $normalized, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
      return NULL;
    }
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $result->finalize();
    if (!$row || !isset($row['offset'])) {
      return NULL;
    }
    return (int) $row['offset'];
  }

  protected function getEditionDumpHandle() {
    if ($this->editionDumpPath === NULL) {
      return NULL;
    }
    if (!is_resource($this->editionDumpHandle)) {
      $this->editionDumpHandle = @fopen($this->editionDumpPath, 'rb');
      if ($this->editionDumpHandle === FALSE) {
        $this->logger->error('Failed opening edition dump path @path.', ['@path' => $this->editionDumpPath]);
        $this->editionDumpHandle = NULL;
        $this->editionDumpPath = NULL;
      }
    }
    return $this->editionDumpHandle;
  }

  protected function loadEditionPayloadFromFile(string $editionKey, ?array &$debug = NULL) {
    $offset = $this->getEditionOffset($editionKey);
    if ($offset === NULL) {
      if (is_array($debug)) {
        $debug['dump_queries'][] = [
          'edition' => $editionKey,
          'status' => 'no_index',
        ];
      }
      return FALSE;
    }
    $handle = $this->getEditionDumpHandle();
    if (!is_resource($handle)) {
      if (is_array($debug)) {
        $debug['dump_queries'][] = [
          'edition' => $editionKey,
          'offset' => $offset,
          'status' => 'no_dump',
        ];
      }
      return FALSE;
    }
    if (fseek($handle, $offset) !== 0) {
      $this->logger->error('Failed seeking to offset @offset for edition @edition.', [
        '@offset' => $offset,
        '@edition' => $editionKey,
      ]);
      if (is_array($debug)) {
        $debug['dump_queries'][] = [
          'edition' => $editionKey,
          'offset' => $offset,
          'status' => 'seek_failed',
        ];
      }
      return FALSE;
    }
    $line = fgets($handle);
    if ($line === FALSE) {
      $this->logger->error('Failed reading edition data for @edition at offset @offset.', [
        '@edition' => $editionKey,
        '@offset' => $offset,
      ]);
      if (is_array($debug)) {
        $debug['dump_queries'][] = [
          'edition' => $editionKey,
          'offset' => $offset,
          'status' => 'read_failed',
        ];
      }
      return FALSE;
    }
    $line = rtrim($line, "\r\n");
    $parts = explode("\t", $line, 5);
    if (count($parts) < 5) {
      if (is_array($debug)) {
        $debug['dump_queries'][] = [
          'edition' => $editionKey,
          'offset' => $offset,
          'status' => 'invalid_record',
        ];
      }
      return FALSE;
    }
    $json = $parts[4];
    $payload = json_decode($json, TRUE);
    if (!is_array($payload)) {
      if (is_array($debug)) {
        $debug['dump_queries'][] = [
          'edition' => $editionKey,
          'offset' => $offset,
          'status' => 'json_error',
        ];
      }
      return FALSE;
    }
    if (is_array($debug)) {
      $debug['dump_queries'][] = [
        'edition' => $editionKey,
        'offset' => $offset,
        'status' => 'hit',
      ];
    }
    return $payload;
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
