<?php

/**
 * @file
 * Contains Drupal\search_api\Processor\FieldsProcessorPluginBase.
 */

namespace Drupal\search_api\Processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\FilterInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility\Utility;

/**
 * Provides a base class for processors that work on individual fields.
 *
 * A form element to select the fields to run on is provided, as well as easily
 * overridable methods to provide the actual functionality. Subclasses can
 * override any of these methods (or the interface methods themselves, of
 * course) to provide their specific functionality:
 * - processField()
 * - processFieldValue()
 * - processKeys()
 * - processKey()
 * - processFilters()
 * - processFilterValue()
 * - process()
 *
 * The following methods can be used for specific logic regarding the fields to
 * run on:
 * - testField()
 * - testType()
 */
abstract class FieldsProcessorPluginBase extends ProcessorPluginBase {

  // @todo Add defaultConfiguration() implementation and find a cleaner solution
  //   for all the isset($this->configuration['fields']) checks.

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $fields = $this->index->getFields();
    $field_options = array();
    $default_fields = array();
    if (isset($this->configuration['fields'])) {
      $default_fields = array_filter($this->configuration['fields']);
    }
    foreach ($fields as $name => $field) {
      if ($this->testType($field->getType())) {
        $field_options[$name] = $field->getPrefixedLabel();
        if (!isset($this->configuration['fields']) && $this->testField($name, $field)) {
          $default_fields[$name] = $name;
        }
      }
    }

    $form['fields'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Enable this processor on the following fields'),
      '#options' => $field_options,
      '#default_value' => $default_fields,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $fields = array_filter($form_state->getValues()['fields']);
    if ($fields) {
      $fields = array_fill_keys($fields, TRUE);
    }
    $form_state->setValue('fields', $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIndexItems(array &$items) {
    // Annoyingly, this doc comment is needed for PHPStorm. See
    // http://youtrack.jetbrains.com/issue/WI-23586
    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item) {
      foreach ($item->getFields() as $name => $field) {
        if ($this->testField($name, $field)) {
          $this->processField($field);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {
    $keys = &$query->getKeys();
    if (isset($keys)) {
      $this->processKeys($keys);
    }
    $filter = $query->getFilter();
    $filters = &$filter->getFilters();
    $this->processFilters($filters);
  }

  /**
   * Processes a single field's value.
   *
   * Calls process() either for each value, or each token, depending on the
   * type. Also takes care of extracting list values and of fusing returned
   * tokens back into a one-dimensional array.
   *
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   The field to process.
   */
  protected function processField(FieldInterface $field) {
    $values = $field->getValues();
    $type = $field->getType();

    foreach ($values as $i => &$value) {
      // We restore the field's type for each run of the loop since we need the
      // unchanged one as long as the current field value hasn't been updated.
      $type = $field->getType();
      if ($type == 'tokenized_text') {
        foreach ($value as &$tokenized_value) {
          $this->processFieldValue($tokenized_value['value'], $type);
        }
      }
      else {
        $this->processFieldValue($value, $type);
      }

      if ($type == 'tokenized_text') {
        $value = $this->normalizeTokens($value);
      }
      elseif ($value === '') {
        unset($values[$i]);
      }
    }

    // We're also setting the type here as it could have changed.
    $field->setType($type);
    $field->setValues($values);
  }

  /**
   * Normalizes an internal array of tokens, which might be nested.
   *
   * @param array $tokens
   *   An array of tokens, possibly nested.
   * @param int $score
   *   (optional) The score to use as a multiplier for all of the tokens
   *   contained in this array of tokens. Used internally.
   *
   * @return array
   *   A normalized tokens array, without any nested tokens arrays.
   */
  protected function normalizeTokens(array $tokens, $score = 1) {
    $ret = array();
    foreach ($tokens as $token) {
      if ($token['value'] === '') {
        // Filter out empty tokens.
        continue;
      }
      if (!isset($token['score'])) {
        $token['score'] = $score;
      }
      else {
        $token['score'] *= $score;
      }
      if (is_array($token['value'])) {
        foreach ($this->normalizeTokens($token['value'], $token['score']) as $t) {
          $ret[] = $t;
        }
      }
      else {
        $ret[] = $token;
      }
    }
    return $ret;
  }

  /**
   * Preprocesses the search keywords.
   *
   * Calls processKey() for individual strings.
   *
   * @param array|string $keys
   *   Either a parsed keys array, or a single keywords string.
   */
  protected function processKeys(&$keys) {
    if (is_array($keys)) {
      foreach ($keys as $key => &$v) {
        if (Element::child($key)) {
          $this->processKeys($v);
          if ($v === '') {
            unset($keys[$key]);
          }
        }
      }
    }
    else {
      $this->processKey($keys);
    }
  }

  /**
   * Preprocesses the query filters.
   *
   * @param array $filters
   *   An array of filters, passed by reference. The contents follow the format
   *   of \Drupal\search_api\Query\FilterInterface::getFilters().
   */
  protected function processFilters(array &$filters) {
    $fields = $this->index->getFields();
    foreach ($filters as $key => &$f) {
      if (is_array($f)) {
        if (isset($fields[$f[0]]) && $this->testField($f[0], $fields[$f[0]])) {
          // We want to allow processors also to easily remove complete filters.
          // However, we can't use empty() or the like, as that would sort out
          // filters for 0 or NULL. So we specifically check only for the empty
          // string, and we also make sure the filter value was actually changed
          // by storing whether it was empty before.
          $empty_string = $f[1] === '';
          $this->processFilterValue($f[1]);

          if ($f[1] === '' && !$empty_string) {
            unset($filters[$key]);
          }
        }
      }
      elseif ($f instanceof FilterInterface) {
        $child_filters = &$f->getFilters();
        $this->processFilters($child_filters);
      }
    }
  }

  /**
   * Tests whether a certain field should be processed.
   *
   * @param string $name
   *   The field's machine name.
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   The field's information.
   *
   * @return bool
   *   TRUE if the field should be processed, FALSE otherwise.
   */
  protected function testField($name, FieldInterface $field) {
    if (!isset($this->configuration['fields'])) {
      return $this->testType($field->getType());
    }
    return !empty($this->configuration['fields'][$name]);
  }

  /**
   * Determines whether a field of a certain type should be preprocessed.
   *
   * The default implementation returns TRUE for "text", "tokenized_text" and
   * "string".
   *
   * @param string $type
   *   The type of the field (either when preprocessing the field at index time,
   *   or a filter on the field at query time).
   *
   * @return bool
   *   TRUE if fields of that type should be processed, FALSE otherwise.
   */
  protected function testType($type) {
    return Utility::isTextType($type, array('text', 'tokenized_text', 'string'));
  }

  /**
   * Processes a single text element in a field.
   *
   * The default implementation just calls process().
   *
   * @param string $value
   *   The string value to preprocess, as a reference. Can be manipulated
   *   directly, nothing has to be returned. Can either be left a string, or
   *   changed into an array of tokens. A token is an associative array
   *   containing:
   *   - value: Either the text inside the token, or a nested array of tokens.
   *     The score of nested tokens will be multiplied by their parent's score.
   *   - score: The relative importance of the token, as a float, with 1 being
   *     the default.
   * @param string $type
   *   The field type as a reference. If the method changes the field's type,
   *   this parameter has to be updated accordingly. A common example would be
   *   changing text to tokenized_text. If an implementation updates the type,
   *   however, it has to do so regardless of the $value passed – otherwise, the
   *   behavior is undefined.
   */
  protected function processFieldValue(&$value, &$type) {
    $this->process($value);
  }

  /**
   * Processes a single search keyword.
   *
   * The default implementation just calls process().
   *
   * @param string $value
   *   The string value to preprocess, as a reference. Can be manipulated
   *   directly, nothing has to be returned. Can either be left a string, or be
   *   changed into a nested keys array, as defined by
   *   \Drupal\search_api\Query\QueryInterface::getKeys().
   */
  protected function processKey(&$value) {
    $this->process($value);
  }

  /**
   * Processes a single filter value.
   *
   * Called for processing a single filter value. The default implementation
   * just calls process().
   *
   * @param string $value
   *   The string value to preprocess, as a reference. Can be manipulated
   *   directly, nothing has to be returned. Has to remain a string. Set to an
   *   empty string to remove the filter.
   */
  protected function processFilterValue(&$value) {
    $this->process($value);
  }

  /**
   * Processes a single string value.
   *
   * This method is ultimately called for all text by the standard
   * implementation, and does nothing by default.
   *
   * @param string $value
   *   The string value to preprocess, as a reference. Can be manipulated
   *   directly, nothing has to be returned. Since this can be called for all
   *   value types, $value has to remain a string.
   */
  protected function process(&$value) {}

}
