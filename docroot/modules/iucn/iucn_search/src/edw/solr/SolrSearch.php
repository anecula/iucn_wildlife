<?php

/**
 * @file
 * Contains \Drupal\iucn_search\edw\solr\solrSearch.
 */

namespace Drupal\iucn_search\edw\solr;


use Solarium\QueryType\Select\Result\Document;
use Solarium\QueryType\Select\Result\DocumentInterface;

class SearchResult {

  private $results = array();
  private $countTotal = 0;

  public function __construct($results, $count) {
    $this->results = $results;
    $this->countTotal = $count;
  }

  public function getResults() {
    return $this->results;
  }

  public function getCountTotal() {
    return $this->countTotal;
  }
}

class SolrSearch {

  /** @var array Request parameters (query) */
  protected $parameters = NULL;
  /** @var \Drupal\iucn_search\edw\solr\SolrSearchServer */
  protected $server = NULL;
  protected $facets = array();

  public function __construct(array $parameters, SolrSearchServer $server) {
    $this->parameters = $parameters;
    $this->server = $server;
    $this->facets = \Drupal::service('module_handler')->invokeAll('edw_search_solr_facet_info', array('server' => $server));
    \Drupal::service('module_handler')->alter('edw_search_solr_facet_info', $this->facets, $server);
  }

  /**
   * @param $page
   * @param $size
   * @return \Drupal\iucn_search\edw\solr\SearchResult
   *   Results
   */
  public function search($page, $size) {
    $search_text = $this->getParameter('q');
    $query = $this->server->createSelectQuery();
    $query_fields = array_values($this->server->getSearchFieldsMappings());
    $solr_id_field = $this->server->getDocumentIdField();

    $query->setQuery($search_text);
    $query->setFields(array('*', 'score'));
    $query->getEDisMax()->setQueryFields(implode(' ', $query_fields));
    $offset = $page * $size;
    $query->setStart($offset);
    $query->setRows($size);

    // Handle the facets
    $facet_set = $query->getFacetSet();
    $facet_set->setSort('count');
    $facet_set->setLimit(10);
    $facet_set->setMinCount(1);
    $facet_set->setMissing(FALSE);
    /** @var SolrFacet $facet */
    foreach ($this->facets as $facet) {
      $facet->render(SolrFacet::$RENDER_CONTEXT_SOLR, $query, $facet_set, $this->parameters);
    }
    $resultSet = $this->server->executeSearch($query);
    $this->updateFacetValues($resultSet->getFacetSet());
    $documents = $resultSet->getDocuments();
    $countTotal = $resultSet->getNumFound();

    $ret = array();
    /** @var Document $document */
    foreach ($documents as $document) {
      $fields = $document->getFields();
      $id = $fields[$solr_id_field];
      if (is_array($id)) {
        $id = reset($nid);
      }
      $ret[$id] = array('id' => $id);
    }
    return new SearchResult($ret, $countTotal);
  }

  public function getParameter($name) {
    $ret = NULL;
    if (!empty($this->parameters[$name])) {
      $ret = $this->parameters[$name];
      // @todo: Security check input parameters
    }
    return $ret;
  }

  public function getFacets() {
    return $this->facets;
  }

  private function updateFacetValues($facetSet) {
    /** @var SolrFacet $facet */
    foreach ($this->getFacets() as $facet_id => $facet) {
      $solrFacet = $facetSet->getFacet($facet_id);
      $values = $solrFacet->getValues();
      if ($request_parameters = $this->getParameter($facet_id)) {
        // Preserve user selection - add filters request.
        $sticky = explode(',', $_GET[$facet_id]);
        if (!empty($sticky)) {
          foreach ($sticky as $key) {
            if (!array_key_exists($key, $values)) {
              $values[$key] = 0;
            }
          }
        }
      }
      $facet->setValues($values);
    }
  }

  public function getHttpQueryParameters() {
    $query = [];
    if ($q = $this->getParameter('q')) {
      $query['q'] = $q;
    }
    /** @var SolrFacet $facet */
    foreach ($this->getFacets() as $facet_id => $facet) {
      $query = array_merge($query, $facet->render(SolrFacet::$RENDER_CONTEXT_GET));
    }
    return $query;
  }
}
