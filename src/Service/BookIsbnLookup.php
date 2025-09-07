<?php

namespace Drupal\wlt_bookshop\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

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

    $seen = [];
    $results = [];
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
      // Build a list of candidate edition keys (cover_edition_key and edition_key[]).
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

      foreach ($candidateKeys as $editionKey) {
        if ($editionKey === '' || isset($seen[$editionKey])) {
          continue;
        }
        $seen[$editionKey] = TRUE;

        $editionUrl = 'https://openlibrary.org/books/' . rawurlencode($editionKey) . '.json';
        try {
          $editionResponse = $this->httpClient->request('GET', $editionUrl, [
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

        // Prefer ISBN-13, but collect both sets.
        if (!empty($edition['isbn_13']) && is_array($edition['isbn_13'])) {
          foreach ($edition['isbn_13'] as $isbn) {
            $isbn = (string) $isbn;
            if ($isbn !== '') {
              $results[$isbn] = TRUE;
            }
          }
        }
        if (!empty($edition['isbn_10']) && is_array($edition['isbn_10'])) {
          foreach ($edition['isbn_10'] as $isbn) {
            $isbn = (string) $isbn;
            if ($isbn !== '') {
              $results[$isbn] = TRUE;
            }
          }
        }

        if (is_array($debug)) {
          $debugEntry = [
            'edition' => $editionKey,
            'url' => $editionUrl,
          ];
          if (!empty($edition['isbn_13'])) {
            $debugEntry['isbn_13'] = $edition['isbn_13'];
          }
          if (!empty($edition['isbn_10'])) {
            $debugEntry['isbn_10'] = $edition['isbn_10'];
          }
          $debug['editions'][] = $debugEntry;
        }
      }
    }

    $all = array_keys($results);
    if (is_array($debug)) {
      $debug['found_isbns'] = $all;
    }
    return $all;
  }

}

