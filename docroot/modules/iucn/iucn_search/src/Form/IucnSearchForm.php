<?php

/**
 * @file
 * Contains \Drupal\iucn_search\Form\IucnSearchForm.
 */

namespace Drupal\iucn_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\iucn_search\edw\solr\SearchResult;
use Drupal\iucn_search\edw\solr\SolrSearchServer;
use Drupal\iucn_search\edw\solr\SolrSearch;
use Drupal\iucn_search\edw\solr\SolrFacet;

class IucnSearchForm extends FormBase {

  protected $items_per_page = 10;
  protected $items_viewmode = 'search_result';
  protected $resultCount = 0;
  protected $search = NULL;

  public function __construct() {
    try {
      $server_config = new SolrSearchServer('default_node_index');
      $this->search = new SolrSearch($_GET, $server_config);
    }
    catch (\Exception $e) {
      watchdog_exception('iucn_search', $e);
      drupal_set_message($this->t('An error occurred.'), 'error');
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
    $current_page = !empty($_GET['page']) ? $_GET['page'] : 0;
    $results = array();
    /** @var SearchResult $result */
    try {
      if ($result = $this->search->search($current_page, $this->items_per_page)) {
        pager_default_initialize($result->getCountTotal(), $this->items_per_page);
        $rows = $result->getResults();
        foreach($rows as $nid => $data) {
          $node = \Drupal\node\Entity\Node::load($nid);
          $results[$nid] = \Drupal::entityTypeManager()->getViewBuilder('node')->view($node, $this->items_viewmode);
        }
      }
    }
    catch(\Exception $e) {
      drupal_set_message(
        strtr('An error occured: !message', array('!message' => $e->getMessage())),
        'error'
      );
      if (function_exists('dpm')) {
        dpm($e->__toString());
      }
    }

    $form['row'] = [
      '#attributes' => ['class' => ['row']],
      '#type' => 'container'
    ];
    $form['row'][] = [
      '#attributes' => ['class' => ['col-md-3', 'col-md-push-9', 'search-facets', 'invisible']],
      '#type' => 'container',
      [
        '#title' => $this->t('Search filters'),
        '#type' => 'fieldset',
        $this->getRenderedFacets()
      ],
      [
        '#attributes' => [
          'class' => ['btn', 'btn-default', 'btn-sm', 'btn-block'],
          'type' => 'reset'
        ],
        '#tag' => 'button',
        '#type' => 'html_tag',
        '#value' => $this->t('Reset all filters')
      ]
    ];
    $form['row'][] = [
      '#attributes' => ['class' => ['col-md-6', 'col-md-pull-3', 'search-results']],
      '#type' => 'container',
      $results
    ];
    $form['pager'] = [
      '#type' => 'pager'
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search')
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = $this->search->getHttpQueryParameters($form_state);
    $form_state->setRedirect('iucn.search', [], ['query' => $query]);
  }

  private function getRenderedFacets() {
    $return = [];
    /** @var SolrFacet $facet */
    foreach ($this->search->getFacets() as $facet_id => $facet) {
      $return[$facet_id] = $facet->renderAsWidget($_GET);
    }
    return $return;
  }
}
