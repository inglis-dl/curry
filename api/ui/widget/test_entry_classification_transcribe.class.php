<?php
/**
 * test_entry_classification_transcribe.class.php
 *
 * @author Dean Inglis <inglisd@mcmaster.ca>
 * @filesource
 */

namespace cedar\ui\widget;
use cenozo\lib, cenozo\log, cedar\util;

/**
 * widget test_entry_classification transcribe
 */
class test_entry_classification_transcribe extends base_transcribe
{
  /**
   * Constructor.
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @param array $args An associative array of arguments to be processed by the widget
   * @access public
   */
  public function __construct( $args )
  {
    parent::__construct( 'test_entry_classification', $args );
  }

  /**
   * Sets up the operation with any pre-execution instructions that may be necessary.
   *
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @throws exception\runtime
   * @access protected
   */
  protected function setup()
  {
    parent::setup();

    $db_test_entry = $this->parent->get_record();
    $db_test = $db_test_entry->get_test();

    $modifier = lib::create( 'database\modifier' );
    $modifier->order( 'rank' );
    $entry_data = array();

    foreach( $db_test_entry->get_test_entry_classification_list( $modifier ) as
             $db_test_entry_classification )
    {
      $db_word = $db_test_entry_classification->get_word();
      $word = '';
      $word_id = '';
      $classification = '';

      if( !is_null( $db_test_entry_classification->word_id ) )
      {
        $data = $db_test->get_word_classification(
          NULL, $db_test_entry_classification->word_id, NULL );
        $word = $data['word'];
        $word_id = $data['word_id'];
        $classification = $data['classification'];
      }

      $row = array(
               'id' => $db_test_entry_classification->id,
               'rank' => $db_test_entry_classification->rank,
               'word_id' => $word_id,
               'word' => $word,
               'classification' => $classification );

      $entry_data[] = $row;
    }

    $this->set_variable( 'entry_data', $entry_data );
  }
}
