<?php

/**
 * @file
 * Contains \Drupal\iucn_search\Form\IucnSearchForm.
 */

namespace Drupal\iucn_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Entity\Index;
use Drupal\iucn_search\Edw\Facets\Facet;
use Solarium\Client;
use Solarium\Core\Client\Request;

class IucnSearchForm extends FormBase {

  protected $search_url_param = 'q';

  protected $items_per_page = 10;

  protected $items_viewmode = 'search_index';

  protected $resultCount = 0;

  /**
   * A connection to the Solr server.
   *
   * @var \Solarium\Client
   */
  protected $solr;

  /**
   * Configuration for solr server.
   */
  protected $solr_configuration;

  /**
   * Search api index.
   *
   * @var \Drupal\search_api\Entity\Index
   */
  protected $index;

  protected $facets = [];

  public function __construct() {
    try {
      $this->index = Index::load('default_node_index');
      $server = $this->index->getServerInstance();
      $this->solr_configuration = $server->getBackendConfig() + array('key' => $server->id());
      $this->solr = new Client();
      $this->solr->createEndpoint($this->solr_configuration, TRUE);
    }
    catch (\Exception $e) {
      watchdog_exception('iucn_search', $e);
      drupal_set_message(t('An error occurred.'), 'error');
    }


    // @ToDo: Translate facet titles
    $facets = [
      'Country' => [
        'title' => 'Country',
        'field' => 'field_country',
        'entity_type' => 'node',
        'bundle' => 'country',
      ],
      'Type' => [
        'title' => 'Type',
        'field' => 'field_type_of_text',
        'entity_type' => 'term',
        'bundle' => 'document_types',
      ],
    ];
    foreach ($facets as $facet) {
      $this->facets[] = new Facet(
        $facet['title'],
        $facet['field'],
        'or',
        '10',
        '1',
        $facet['entity_type'],
        $facet['bundle']
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'iucn_search_form';
  }

  /**
   * {@inheritdoc}.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $text = !empty($_GET[$this->search_url_param]) ? $_GET[$this->search_url_param] : '';
    $current_page = !empty($_GET['page']) ? $_GET['page'] : 0;
    $results = $this->getSeachResults($text, $current_page);
    pager_default_initialize($this->resultCount, $this->items_per_page);
    $form['text'] = [
      '#type' => 'textfield',
      '#title' => 'Search text',
      '#default_value' => $text,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Search',
    ];
    $elements = [
      '#theme' => 'iucn_search_results',
      '#items' => $results,
    ];
    $form['display'] = [
      'results' => [
        'nodes' => ['#markup' => \Drupal::service('renderer')->render($elements)],
        'pager' => ['#type' => 'pager'],
      ],
      'facets' => $this->getRenderedFacets(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validation is optional.
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = [];
    $search_text = $form_state->getValue('text');
    $query[$this->search_url_param] = $search_text;
    foreach ($this->facets as $facet) {
      $values = [];
      foreach ($form_state->getValue((string) $facet) as $value => $selected) {
        if ($selected) {
          $values[] = $value;
        }
      }
      if (!empty($values)) {
        $query[(string) $facet] = implode(',', $values);
      }
    }
    $form_state->setRedirect('iucn.search', [], ['query' => $query]);
  }

  private function setQueryFacets(\Drupal\search_api\Query\QueryInterface &$query) {
    $query_facets = [];
    foreach ($this->facets as $facet) {
      $field = (string) $facet;
      if (!empty($_GET[$field])) {
        $conditionGroup = $query->createConditionGroup($facet->getOperator(), $field);
        foreach (explode(',', $_GET[$field]) as $val) {
          $conditionGroup->addCondition($field, $val);
        }
        $query->addConditionGroup($conditionGroup);
      }
      $query_facets[] = $facet->getArray();
    }
    $query->setOption('search_api_facets', $query_facets);
  }

  private function setFacetsValues(array $values) {
    foreach ($this->facets as $key => &$facet) {
      $facet->setValues($values[$key]);
    }
  }

  private function getRenderedFacets() {
    $return = [];
    foreach ($this->facets as $facet) {
      $return[(string) $facet] = $facet->render();
    }
    return $return;
  }

  private function createSolariumRequest($solarium_query) {
    // Use the 'postbigrequest' plugin if no specific http method is
    // configured. The plugin needs to be loaded before the request is
    // created.
    if ($this->solr_configuration['http_method'] == 'AUTO') {
      $this->solr->getPlugin('postbigrequest');
    }

    $request = $this->solr->createRequest($solarium_query);

    if ($this->solr_configuration['http_method'] == 'POST') {
      $request->setMethod(Request::METHOD_POST);
    }
    elseif ($this->solr_configuration['http_method'] == 'GET') {
      $request->setMethod(Request::METHOD_GET);
    }
    if (strlen($this->solr_configuration['http_user']) && strlen($this->solr_configuration['http_pass'])) {
      $request->setAuthentication($this->solr_configuration['http_user'], $this->solr_configuration['http_pass']);
    }

    // Send search request.
    $response = $this->solr->executeRequest($request);
    $resultSet = $this->solr->createResult($solarium_query, $response);

    return $resultSet;
  }

  private function getSeachResults($search_text, $current_page) {
    $nodes = [];
    $solarium_query = $this->solr->createSelect();
    $solarium_query->setQuery($search_text);
    $solarium_query->setFields(array('*', 'score'));

    $field_names = $this->index->getServerInstance()->getBackend()->getFieldNames($this->index);
    $search_fields = $this->index->getFulltextFields();
    // Get the index fields to be able to retrieve boosts.
    $index_fields = $this->index->getFields();
    $query_fields = [];
    foreach ($search_fields as $search_field) {
      /** @var \Solarium\QueryType\Update\Query\Document\Document $document */
      $document = $index_fields[$search_field];
      $boost = $document->getBoost() ? '^' . $document->getBoost() : '';
      $query_fields[] = $field_names[$search_field] . $boost;
    }
    $solarium_query->getEDisMax()->setQueryFields(implode(' ', $query_fields));

    $resultSet = $this->createSolariumRequest($solarium_query);
    $documents = $resultSet->getDocuments();

    foreach ($documents as $document) {
      $fields = $document->getFields();
      $nid = $fields[$field_names['nid']];
      if (is_array($nid)) {
        $nid = reset($nid);
      }
      $node = \Drupal\node\Entity\Node::load($nid);
      $nodes[$nid] = \Drupal::entityTypeManager()->getViewBuilder('node')->view($node, $this->items_viewmode);
    }

//    if (empty($index = Index::load('default_node_index'))) {
//      drupal_set_message(t('The search index is not properly configured.'), 'error');
//      return $results;
//    }
//    try {
//      $query = $index->query();
//      $query->keys($search_text);
//      $offset = $current_page * $this->items_per_page;
//      $query->range($offset, $this->items_per_page);
//      $this->setQueryFacets($query);
//      $resultSet = $query->execute();
//
//      $this->resultCount = $resultSet->getResultCount();
//      $this->setFacetsValues($resultSet->getExtraData('search_api_facets'));
//
//      foreach ($resultSet->getResultItems() as $item) {
//        $item_nid = $item->getField('nid')->getValues()[0];
//        $node = \Drupal\node\Entity\Node::load($item_nid);
//        $nodes[$item_nid] = \Drupal::entityTypeManager()->getViewBuilder('node')->view($node, $this->items_viewmode);
//      }
//    }
//    catch (\Exception $e) {
//      watchdog_exception('iucn_search', $e);
//      drupal_set_message(t('An error occurred.'), 'error');
//    }
    return $nodes;
  }

}
