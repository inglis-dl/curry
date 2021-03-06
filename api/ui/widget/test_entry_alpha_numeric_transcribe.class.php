<?php
/**
 * test_entry_alpha_numeric_transcribe.class.php
 * 
 * @author Dean Inglis <inglisd@mcmaster.ca>
 * @filesource
 */

namespace cedar\ui\widget;
use cenozo\lib, cenozo\log, cedar\util;

/**
 * widget test_entry_alpha_numeric transcribe
 */
class test_entry_alpha_numeric_transcribe extends \cenozo\ui\widget
{
  /** 
   * Constructor.
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @param array $args An associative array of arguments to be processed by the widget
   * @access public
   */
  public function __construct( $args )
  {
    parent::__construct( 'test_entry_alpha_numeric', 'transcribe', $args );
  }

  /**
   * Processes arguments, preparing them for the operation.
   * 
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @throws exception\notice
   * @access protected
   */
  protected function prepare()
  {
    parent::prepare();

    // parent must be a test_entry_transcribe widget
    if( is_null( $this->parent ) ) 
      throw lib::create( 'exception\runtime', 'This class must have a parent', __METHOD__ );
   
    $db_test_entry = $this->parent->get_record();

    $db_test = $db_test_entry->get_test();
    $heading = $db_test->name . ' test entry form';

    //TODO put this somewhere else
    if( $db_test_entry->deferred )
      $heading = $heading . ' NOTE: this test is currently deferred';

    $this->set_heading( $heading );
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
    $test_type_name = $db_test->get_test_type()->name;

    if( $test_type_name != 'alpha_numeric' )
      throw lib::create( 'exception\runtime',
              'Widget requires test type to be alpha numeric, not ' .
              $test_type_name, __METHOD__ );

    $modifier = lib::create( 'database\modifier' );
    $modifier->order( 'rank' );
    $entry_data = array();
    //TODO add variable for dictionary lookup text completion
    foreach( $db_test_entry->get_test_entry_alpha_numeric_list( $modifier ) as 
      $db_test_entry_alpha_numeric )
    {
      $db_word = is_null(  $db_test_entry_alpha_numeric->word_id ) ? null :
        lib::create( 'database\word', $db_test_entry_alpha_numeric->word_id );
      $row = array(
               'id' => $db_test_entry_alpha_numeric->id,
               'rank' => $db_test_entry_alpha_numeric->rank,
               'word_id' => is_null( $db_word ) ? '' :  $db_word->id,
               'word' => is_null( $db_word ) ? '' :  $db_word->word );

      $entry_data[] = $row;              
    }
    $this->set_variable( 'entry_data', $entry_data );
  }
}
