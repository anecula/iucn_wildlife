<?php

namespace Drupal\Tests\linkit\Kernel\Matchers;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Language\LanguageInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\Tests\linkit\Kernel\LinkitKernelTestBase;

/**
 * Tests term matcher.
 *
 * @todo: Use TaxonomyTestTrait when the methods allow us to define own values.
 *
 * @group linkit
 */
class TermMatcherTest extends LinkitKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['taxonomy'];

  /**
   * The matcher manager.
   *
   * @var \Drupal\linkit\MatcherManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');

    $this->manager = $this->container->get('plugin.manager.linkit.matcher');

    $testing_vocabulary_1 = $this->createVocabulary('testing_vocabulary_1');
    $testing_vocabulary_2 = $this->createVocabulary('testing_vocabulary_2');

    $this->createTerm($testing_vocabulary_1, ['name' => 'foo_bar']);
    $this->createTerm($testing_vocabulary_1, ['name' => 'foo_baz']);
    $this->createTerm($testing_vocabulary_1, ['name' => 'foo_foo']);
    $this->createTerm($testing_vocabulary_1, ['name' => 'bar']);
    $this->createTerm($testing_vocabulary_2, ['name' => 'foo_bar']);
    $this->createTerm($testing_vocabulary_2, ['name' => 'foo_baz']);
  }

  /**
   * Tests term matcher with default configuration.
   */
  public function testTermMatcherWidthDefaultConfiguration() {
    /** @var \Drupal\linkit\MatcherInterface $plugin */
    $plugin = $this->manager->createInstance('entity:taxonomy_term', []);
    $suggestions = $plugin->execute('foo');
    $this->assertEquals(5, count($suggestions->getSuggestions()), 'Correct number of suggestions');
  }

  /**
   * Tests term matcher with bundle filer.
   */
  public function testTermMatcherWidthBundleFiler() {
    /** @var \Drupal\linkit\MatcherInterface $plugin */
    $plugin = $this->manager->createInstance('entity:taxonomy_term', [
      'settings' => [
        'bundles' => [
          'testing_vocabulary_1' => 'testing_vocabulary_1',
        ],
      ],
    ]);

    $suggestions = $plugin->execute('foo');
    $this->assertEquals(3, count($suggestions->getSuggestions()), 'Correct number of suggestions');
  }

  /**
   * Creates and saves a vocabulary.
   *
   * @param string $name
   *   The vocabulary name.
   *
   * @return VocabularyInterface The new vocabulary object.
   *   The new vocabulary object.
   */
  private function createVocabulary($name) {
    $vocabularyStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary');
    $vocabulary = $vocabularyStorage->create([
      'name' => $name,
      'description' => $name,
      'vid' => Unicode::strtolower($name),
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);
    $vocabulary->save();
    return $vocabulary;
  }

  /**
   * Creates and saves a new term with in vocabulary $vid.
   *
   * @param \Drupal\taxonomy\VocabularyInterface $vocabulary
   *   The vocabulary object.
   * @param array $values
   *   (optional) An array of values to set, keyed by property name. If the
   *   entity type has bundles, the bundle key has to be specified.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   The new taxonomy term object.
   */
  private function createTerm(VocabularyInterface $vocabulary, $values = []) {
    $filter_formats = filter_formats();
    $format = array_pop($filter_formats);

    $termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $term = $termStorage->create($values + [
      'name' => $this->randomMachineName(),
      'description' => [
        'value' => $this->randomMachineName(),
        // Use the first available text format.
        'format' => $format->id(),
      ],
      'vid' => $vocabulary->id(),
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);
    $term->save();
    return $term;
  }

}
