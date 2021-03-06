<?php

namespace Drupal\iucn_search\edw\solr;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigValueException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Solarium\QueryType\Select\Query\Component\FacetSet;
use Solarium\QueryType\Select\Query\Query;

class SolrFacet {

  public static $FACET_DEFAULT_LIMIT = 10;
  public static $FACET_DEFAULT_MIN_COUNT = -1;

  public static $OPERATOR_OR = 'OR';
  public static $OPERATOR_AND = 'AND';

  protected $id;
  protected $values = array();
  protected $config = array();

  public function __construct($drupal_field_id, $bundle, $solr_field_id, array $config) {
    $this->id = $drupal_field_id;
    $this->solr_field_id = $solr_field_id;
    $this->config = $config;
    $this->config['bundle'] = $bundle;
    $this->config['solr_field_id'] = $solr_field_id;
    if ($info = FieldStorageConfig::loadByName('node', $drupal_field_id)) {
      $this->config['entity_type'] = $info->getSetting('target_type');
    }
    $this->validate();
  }

  /**
   * @param FormStateInterface $form_state
   */
  public function renderAsGetRequest($form_state) {
    $query = array();
    $values = array();
    $id = $this->getId();
    foreach ($form_state->getValue($id . '_values') as $value => $selected) {
      if ($selected) {
        $values[] = $value;
      }
    }
    if (!empty($values)) {
      $query[$id] = implode(',', $values);
    }
    if ($op = $form_state->getValue($id . '_operator')) {
      $query[$id . '_operator'] = $op;
    }
    return $query;
  }

  /**
   * @param Query $solarium_query
   * @param array $parameters
   */
  public function alterSolrQuery(Query &$solarium_query, array $parameters) {
    if (!empty($parameters[$this->id])) {
      $operator = $this->getOperator();
      // Check if default operator is over written in parameters.
      if (!empty($parameters[$this->id . '_operator'])) {
        $overwrite_operator = $parameters[$this->id . '_operator'];
        // Make sure that operator is OR or AND.
        if (in_array($overwrite_operator, array(self::$OPERATOR_AND, self::$OPERATOR_OR))) {
          $operator = $overwrite_operator;
          $this->setOperator($operator);
        }
      }
      $values = $parameters[$this->id];
      if (is_array($values)) {
        $filter = (count($values) > 1) ? '(' . implode(" {$operator} ", $values) . ')' : reset($values);
      }
      else {
        $filter = $values;
      }
      $solarium_query->createFilterQuery(array(
          'key' => "facet:{$this->id}",
          'tag' => "facet:{$this->solr_field_id}",
          'query' => "{$this->solr_field_id}:$filter"
      ));
    }
  }

  /**
   * @param FacetSet $facetSet
   */
  public function createSolrFacet(FacetSet &$facetSet) {
    $field = $facetSet->createFacetField($this->id)->setField($this->solr_field_id);
    $operator = $this->getOperator();
    if (!empty($operator) && strtoupper($operator) === 'OR') {
      $field->setExcludes(["facet:{$this->solr_field_id}"]);
    }
    // Set limit, unless it's the default.
    if ($this->getLimit() != self::$FACET_DEFAULT_LIMIT) {
      $field->setLimit($this->getLimit());
    }
    // Set mincount, unless it's the default.
    if ($this->getMinCount() != self::$FACET_DEFAULT_MIN_COUNT) {
      $field->setMinCount($this->getMinCount());
    }
    // @todo: $field->setMissing($this->getMissing());
  }

  public function renderAsWidget($params) {
    $ret = array();
    $options = array();
    foreach ($this->values as $id => $count) {
      $id = str_replace('"', '', $id);
      $entity = NULL;
      switch ($this->getConfigValue('entity_type')) {
        case 'taxonomy_term':
          $entity = \Drupal\taxonomy\Entity\Term::load($id);
          break;
        case 'node':
          $entity = \Drupal\node\Entity\Node::load($id);
          break;
      }
      if (!empty($entity)) {
        $label = $entity->label();
        if ($label) {
          if ($count > 0) {
            $label .= " ({$count})";
          }
          $options[$id] = $label;
        }
      }
    }
    asort($options);
    $widget = $this->getWidget();
    $request_param_name = $this->id . '_operator';
    $request_param_value = !empty($params[$request_param_name]) ? Html::escape($params[$request_param_name]) : '';
    $exposedOperator = [];
    if (!empty($this->config['exposeOperator']) && $this->config['exposeOperator'] == TRUE) {
      $operator_default_value = strtoupper($request_param_value) === self::$OPERATOR_AND;
      $exposedOperator = [
        '#type' => 'checkbox',
        '#default_value' => $operator_default_value,
        '#return_value' => 'AND',
      ];
    }
    $default_value = $this->getRequestParameter($_GET, $this->id);
    switch ($widget) {
      case 'checkboxes':
        $ret = array(
          '#type' => $widget,
          '#title' => $this->getTitle(),
          '#options' => $options,
          '#default_value' => $default_value,
        );
        break;
      case 'select':
        $ret = array(
          '#type' => 'container',
          '#attributes' => [
            'class' => [
              'facet-container',
            ],
          ],
          'title' => [
            '#markup' => "<h4 class='facet-title'>{$this->getTitle()}</h4>",
          ],
          $request_param_name => $exposedOperator,
          $this->id => [
            '#type' => $widget,
            '#options' => $options,
            '#default_value' => $default_value,
            '#multiple' => TRUE,
            '#attributes' => [
              'data-placeholder' => $this->getConfigValue('placeholder', ''),
            ],
          ],
        );
    }
    return $ret;
  }

  protected function validate() {
    if(empty($this->id)) {
      throw new ConfigValueException('Missing field ID for facet');
    }
    if(empty($this->getConfigValue('bundle'))) {
      throw new ConfigValueException('Missing bundle for facet');
    }
    if(empty($this->getConfigValue('entity_type'))) {
      throw new ConfigValueException("Could not determine entity_type for given field {$this->getConfigValue('bundle')}:{$this->id}");
    }
    if(empty($this->getConfigValue('solr_field_id'))) {
      throw new ConfigValueException("Could not determine entity_type for given field {$this->getConfigValue('bundle')}:{$this->id}");
    }
  }

  /**
   * Retrieve a parameter from GET and sanitize.
   *
   * @param array $source
   *   Array with source values (i.e. $_GET)
   * @param string $name
   *   Parameter name
   * @param mixed $default
   *   Default value
   * @return mixed
   *   Parameter value
   */
  protected function getRequestParameter($source, $name, $default = NULL) {
    $ret = $default;
    if (!empty($_GET[$name])) {
      if (is_string($_GET[$name])) {
        return $ret = Html::escape($_GET[$name]);
      }
      else if (is_array($_GET[$name])) {
        $ret = [];
        foreach ($_GET[$name] as $k => $value) {
          $ret[$k] = Html::escape($value);
        }
      }
    }
    return $ret;
}

  public function setValues(array $values) {
    $this->values = $values;
  }

  function getConfigValue($key, $default = NULL) {
    $ret = $default;
    if (!empty($this->config[$key])) {
      $ret = $this->config[$key];
    }
    return $ret;
  }

  function getId() {
    return $this->id;
  }

  function getTitle() {
    return $this->getConfigValue('title', $this->id);
  }

  function getPlaceholder() {
    return $this->getConfigValue('placeholder', '');
  }

  function getOperator() {
    return $this->getConfigValue('operator', 'OR');
  }

  function setOperator($operator) {
    if (!empty($operator)) {
      if (strtoupper($operator) == self::$OPERATOR_OR) {
        $this->config['operator'] = self::$OPERATOR_OR;
      }
      if (strtoupper($operator) == self::$OPERATOR_AND) {
        $this->config['operator'] = self::$OPERATOR_AND;
      }
    }
  }

  function getLimit() {
    return $this->getConfigValue('limit', -1);
  }

  function getMinCount() {
    return $this->getConfigValue('min_count', self::$FACET_DEFAULT_MIN_COUNT);
  }

  function getWidget() {
    return $this->getConfigValue('widget', 'select');
  }

  function getConfig() {
    return $this->config;
  }

  function getFacetedField() {
    return $this->id;
  }

  function getSolrFieldId() {
    return $this->getConfigValue('solr_field_id');
  }

  function getMissing() {
    return $this->getConfigValue('missing', FALSE);
  }
}
