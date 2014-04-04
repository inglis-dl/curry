<?php
/**
 * assignment_manager.class.php
 * 
 * @author Dean Inglis <inglisd@mcmaster.ca>
 * @filesource
 */

namespace cedar\business;
use cenozo\lib, cenozo\log, cedar\util;

/**
 * Manages assignments.
 */
class assignment_manager extends \cenozo\singleton
{
  /**
   * Constructor.
   * 
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @access protected
   */
  protected function __construct()
  {
  }
   
  /** 
   * Initialize an assignment.  All existing test_entry records are deleted
   * and new test_entry records are created.
   * Only assigments that have never been adjudicated or finished can be initialized.
   * This method is typically called during creation of a db_assignment or
   * to reset a db_test_entry.
   *
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @throws exception\notice
   * @param  database\assignment $db_assignment
   * @param  integer $test_id id of test associated with test_entry records to delete and recreate
   * @access public
   */
  public static function initialize_assignment( $db_assignment, $test_id = NULL )
  {
    if( !is_null( $db_assignment->end_datetime ) )
      throw lib::create( 'exception\notice',
        'The assignment for participant UID ' . $db_assignment->get_participant()->uid .
        'is closed and cannot be initialized', __METHOD__ );

    $test_class_name = lib::get_class_name( 'database\test' );
    $test_entry_class_name = lib::get_class_name( 'database\test_entry' );

    // delete test_entry record(s)
    $base_mod = NULL;
    if( !is_null( $test_id ) )
    {
      $base_mod = lib::create( 'database\modifier' );
      $base_mod->where( 'test_id', '=', $test_id );
    }
    $entry_mod = is_null( $base_mod ) ? NULL : clone $base_mod;
    // we want to delete all the daughter entries
    foreach( $db_assignment->get_test_entry_list( $entry_mod ) as $db_test_entry )
    {
      $get_list_function = 'get_test_entry_' . $db_test_entry->get_test_type()->name . '_list';
      foreach( $db_test_entry->$get_list_function() as $db_entry )
        $db_entry->delete();
    }  

    // get sibling assignment, reset test_entry adjudicate value from 1 to NULL
    $db_sibling_assignment = $db_assignment->get_sibling_assignment();
    if( !is_null( $db_sibling_assignment ) )
    {
      $adjudicate_mod = is_null( $base_mod ) ? lib::create( 'database\modifier' ) : clone $base_mod;
      $adjudicate_mod->where( 'adjudicate', '=', true );
      foreach( $db_sibling_assignment->get_test_entry_list( $adjudicate_mod ) as $db_test_entry )
      {
        $db_test_entry->adjudicate = NULL;
        $db_test_entry->save();
      }
    }

    // test battery depends on cohort
    $test_mod = NULL;
    if( !is_null( $test_id ) )
    {
      $test_mod = lib::create( 'database\modifier' );
      $test_mod->where( 'id', '=', $test_id );
    }
    $db_participant = $db_assignment->get_participant();
    if( $db_participant->get_cohort()->name == 'tracking' )
    {
      if( is_null( $test_mod ) ) $test_mod = lib::create( 'database\modifier' );
      $test_mod->where( 'name', 'NOT LIKE', 'FAS%' );
    }

    $language = $db_participant->language;
    $language = is_null( $language ) ? 'en' : $language;

    // create test_entry record(s)
    foreach( $test_class_name::select( $test_mod ) as $db_test )
    { 
      $db_test_entry = lib::create( 'database\test_entry' );
      $db_test_entry->test_id = $db_test->id;
      $db_test_entry->assignment_id = $db_assignment->id;
      $db_test_entry->save();
      // create daughter entry record(s)
      static::initialize_test_entry( $db_test_entry );
    }
  }

  /** 
   * Initialize a test_entry.
   *
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @param  database\test_entry $db_test_entry
   * @access public
   */
  public static function initialize_test_entry( $db_test_entry )
  {
    $word_class_name = lib::get_class_name( 'database\word' );

    $db_test = $db_test_entry->get_test();
    $test_type_name = $db_test->get_test_type()->name;
    $entry_class_name = 'test_entry_' . $test_type_name;

    $db_assignment = $db_test_entry->get_assignment();
    // if null db_assignment then db_test_entry is an ajudication
    if( is_null( $db_assignment ) )
      $db_participant = $db_test_entry->get_participant();
    else
      $db_participant = $db_assignment->get_participant();

    $language = $db_participant->language;
    $language = is_null( $language ) ? 'en' : $language;

    if( $test_type_name == 'ranked_word' )
    {
      $modifier = lib::create( 'database\modifier' );
      $modifier->order( 'rank' );
      foreach( $db_test->get_ranked_word_set_list( $modifier ) as $db_ranked_word_set )
      {
        $db_test_entry_ranked_word = lib::create( 'database\\'. $entry_class_name );
        $db_test_entry_ranked_word->ranked_word_set_id = $db_ranked_word_set->id;
        $db_test_entry_ranked_word->test_entry_id = $db_test_entry->id;
        $db_test_entry_ranked_word->save();
      }
    }
    else if( $test_type_name == 'confirmation' )
    {
      $db_test_entry_confirmation = lib::create( 'database\\'. $entry_class_name );
      $db_test_entry_confirmation->test_entry_id = $db_test_entry->id;
      $db_test_entry_confirmation->save();
    }
    else if( $test_type_name == 'classification' )
    {
      $setting_manager = lib::create( 'business\setting_manager' );
      $max_rank = $setting_manager->get_setting( 'interface', 'classification_max_rank' );
      for( $rank = 1; $rank <= $max_rank; $rank++ )
      {
        $db_test_entry_classification = lib::create( 'database\\'. $entry_class_name );
        $db_test_entry_classification->test_entry_id = $db_test_entry->id;
        $db_test_entry_classification->rank = $rank;
        $db_test_entry_classification->save();
      }
    }
    else if( $test_type_name == 'alpha_numeric' )
    {
      $modifier = lib::create( 'database\modifier' );
      $modifier->where( 'language', '=', $language );
      $word_count = $db_test->get_dictionary()->get_word_count( $modifier );
      for( $rank = 1; $rank <= $word_count; $rank++ )
      {
        $db_test_entry_alpha_numeric = lib::create( 'database\\'. $entry_class_name );
        $db_test_entry_alpha_numeric->test_entry_id = $db_test_entry->id;
        $db_test_entry_alpha_numeric->rank = $rank;
        $db_test_entry_alpha_numeric->save();
      }
    }
  }

  /**
   * Update a test_entry, its assigment, its sibling assignment and its sibling
   * assignment's test_entry based on its complete status.  This method is
   * typically called whenever a daughter table entry is edited.
   * 
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @param  database\test_entry $db_test_entry
   * @access public
   */
  public static function complete_test_entry( $db_test_entry )
  {
    $test_entry_class_name = lib::get_class_name( 'database\test_entry' );
    $db_test = $db_test_entry->get_test();

    $db_test_entry->completed = $db_test_entry->is_completed();
          
    // check if we need to adjudicate
    if( $db_test_entry->completed && !$db_test_entry->deferred )
    {   
      $db_assignment = $db_test_entry->get_assignment();
      // does the sibling assignment exist?
      $db_sibling_assignment = $db_assignment->get_sibling_assignment();
      if( !is_null( $db_sibling_assignment ) ) 
      {   
        // get the sibling test entry
        $db_sibling_test_entry = $test_entry_class_name::get_unique_record(
          array( 'test_id', 'assignment_id' ), 
          array( $db_test->id, $db_sibling_assignment->id ) );

        // only check for adjudication if both tests are complete and not deferred
        if( $db_sibling_test_entry->completed && !$db_sibling_test_entry->deferred ) 
        {   
          // compare the daughter table entries, true if identical
          if( $db_test_entry->compare( $db_sibling_test_entry ) ) 
          {
            // NOTE: test_entry records with adjudicate set to false cannot be deleted
            $db_test_entry->adjudicate = false;
            $db_sibling_test_entry->adjudicate = false;

            $modifier = lib::create( 'database\modifier' );
            $modifier->where( 'assignment_id', 'IN', 
              array( $db_assignment->id, $db_sibling_assignment->id ) );
            // count the number of records requiring adjudication or have not been tested
            $modifier->where( 'IFNULL( adjudicate, 1 )', '=', 1 );

            if( 0 == $test_entry_class_name::count( $modifier ) ) 
            {   
              // both assignments are now complete so set their end datetimes
              $end_datetime = util::get_datetime_object()->format( "Y-m-d H:i:s" );
              $db_assignment->end_datetime = $end_datetime;
              $db_sibling_assignment->end_datetime = $end_datetime;
              $db_assignment->save();
              $db_sibling_assignment->save(); 
            }   
          }   
          else
          {   
            $db_test_entry->adjudicate = true;
            $db_sibling_test_entry->adjudicate = true;
          }   

          $db_sibling_test_entry->save();
        }   
      }   
    }   

    $db_test_entry->save();
  } 

  /**
   * Get the data for adjudicating.
   * 
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @param  database\test_entry $db_test_entry
   * @throws exception\runtime
   * @return array() Data required for the adjudication at the UI layer
   * @access public
   */
  public static function get_adjudicate_data( $db_test_entry )
  {
    $test_entry_class_name = lib::get_class_name( 'database\test_entry' );
    $db_test = $db_test_entry->get_test();
    $test_type_name = $db_test->get_test_type()->name;
    $entry_class_name = 'test_entry_' . $test_type_name;

    $adjudicate_data = NULL;
   
    if( $db_test_entry->adjudicate == true )
    {
      // get the sibling entry
      $db_sibling_assignment = $db_test_entry->get_assignment()->get_sibling_assignment();
      if( !is_null( $db_sibling_assignment ) )
      {
        $db_sibling_test_entry = $test_entry_class_name::get_unique_record(
          array( 'test_id', 'assignment_id' ),
          array( $db_test->id, $db_sibling_assignment->id ) );

        if( $db_sibling_test_entry->adjudicate == true )
        {
          $adjudicate_data = array();
          if( $test_type_name == 'confirmation' )
          {
            $adjudicate_data[ 'id_1' ] = $db_test_entry->id;
            $adjudicate_data[ 'id_2' ] = $db_test_entry_sibling->id;
            $adjudicate_data[ 'confirmation_1' ] = $db_test_entry->confirmation;
            $adjudicate_data[ 'confirmation_2' ] = $db_test_entry_sibling->confirmation;
          }
          else
          {
            $language = $db_test_entry->get_assignment()->get_participant()->language;
            $language = is_null( $language ) ? 'en' : $language;

            $get_list_function = 'get_' . $entry_class_name . '_list';
            $classification = array_combine( 
              array( $db_test->dictionary_id, 
                     $db_test->intrusion_dictionary_id, 
                     $db_test->variant_dictionary_id ),
              array( 'primary', 'intrusion', 'variant' ) ); 

            if( $test_type_name == 'alpha_numeric' || $test_type_name == 'classification' )
            {
              $modifier = lib::create( 'database\modifier' );
              $modifier->order( 'rank' );
              $a = $db_test_entry->$get_list_function( clone $modifier );
              $b = $db_test_entry_sibling->$get_list_function( clone $modifier );

              while( !is_null( key( $a ) ) || !is_null( key ( $b ) ) )
              {
                $a_obj = current( $a );
                $b_obj = current( $b );

                $id_1 = '';
                $id_2 = '';
                $word_id_1 = '';
                $word_id_2 = '';
                $word_1 = '';
                $word_2 = '';
                $adjudicate = false;
                $rank = '';

                // unequal number of list elements case
                if( false === $a_obj )
                {
                  $adjudicate = true;
                  $rank = $b_obj->rank;
                  $id_2 = $b_obj->id;
                  if( !is_null( $b_obj->word_id ) )
                  {
                    $db_word = lib::create( 'database\word', $b_obj->word_id );
                    $word_2 = $db_word->word;
                    $word_id_2 = $db_word->id;
                  }
                }
                // unequal number of list elements case
                else if( false === $b_obj )
                {
                  $adjudicate = true;
                  $rank = $a_obj->rank;
                  $id_1 = $a_obj->id;
                  if( !is_null( $a_obj->word_id ) )
                  {
                    $db_word = lib::create( 'database\word', $a_obj->word_id );
                    $word_1 = $db_word->word;
                    $word_id_1 = $db_word->id;
                  }
                }
                else
                {
                  $rank = $a_obj->rank;

                  if( !( is_null( $a_obj->word_id ) && is_null( $b_obj->word_id ) ) )
                  {
                    $adjudicate = $a_obj->word_id != $b_obj->word_id;

                    if( !is_null( $a_obj->word_id ) )
                    {
                      $db_word = lib::create( 'database\word', $a_obj->word_id );
                      $word_id_1 = $db_word->id;
                      $word_1 = $db_word->word;
                    }

                    if( !is_null( $b_obj->word_id ) )
                    {
                      $db_word = lib::create( 'database\word', $b_obj->word_id );
                      $word_id_2 = $db_word->id;
                      $word_2 = $db_word->word;
                    }
                  }
                }

                $row = array(
                         'id_1' => $id_1,
                         'id_2' => $id_2,
                         'rank' => $rank,
                         'word_id_1' => $word_id_1,
                         'word_1' => $word_1,
                         'word_id_2' => $word_id_2,
                         'word_2' => $word_2,
                         'adjudicate' => $adjudicate );

                // get word classfications
                if( $test_type_name == 'classification' )
                {
                  $classification_1 = '';
                  $classification_2 = '';
                  if( $word_id_1 !== '' )
                  {
                    $db_word = lib::create( 'database\word', $word_id_1 );
                    $dictionary_id = $db_word->dictionary_id;
                    $classification_1 = array_key_exists( $dictionary_id, $classification ) ? 
                      $classification[ $dictionary_id ] : '';
                  }    
                  if( $word_id_2 !== '' )
                  {
                    $db_word = lib::create( 'database\word', $word_id_2 );
                    $dictionary_id = $db_word->dictionary_id;
                    $classification_2 = array_key_exists( $dictionary_id, $classification ) ? 
                      $classification[ $dictionary_id ] : '';
                  }    

                  $row['classification_1'] = $classfication_1;
                  $row['classification_2'] = $classfication_2;
                }

                $adjudicate_data[] = $row;

                next( $a );
                next( $b );
              }
            }
            else if( $test_type_name == 'ranked_word' )
            {
              $modifier = lib::create( 'database\modifier' );
              $modifier->order( 'ranked_word_set.rank' );
              $a = $db_test_entry->$get_list_function( clone $modifier );
              $b = $db_test_entry_sibling->$get_list_function( clone $modifier );

              while( !is_null( key( $a ) ) || !is_null( key ( $b ) ) )
              {
                $a_obj = current( $a );
                $b_obj = current( $b );

                $id_1 = '';
                $id_2 = '';
                $word_id_1 = '';
                $word_id_2 = '';
                $word_1 = '';
                $word_2 = '';
                $classification_1 = '';
                $classification_2 = '';
                $selection_1 = '';
                $selection_2 = '';
                $adjudicate = false;

                // unequal number of list elements case
                if( false === $a_obj )
                {
                  $adjudicate = true;
                  $id_2 = $b_obj->id;
                  $selection_2 = is_null( $b_obj->selection ) ? '' : $b_obj->selection;
                  if( !is_null( $b_obj->word_id ) )
                  {
                    $db_word = lib::create( 'database\word', $b_obj->word_id );
                    $word_2 = $db_word->word;
                    $word_id_2 = $db_word->id;
                    $dictionary_id = $db_word->dictionary_id;
                    $classification_2 = array_key_exists( $dictionary_id, $classification ) ? 
                      $classification[ $dictionary_id ] : '';
                  }
                }
                // unequal number of list elements case
                else if( false === $b_obj )
                {
                  $adjudicate = true;
                  $id_1 = $a_obj->id;
                  $selection_1 = is_null( $a_obj->selection ) ? '' : $a_obj->selection;
                  if( !is_null( $a_obj->word_id ) )
                  {
                    $db_word = lib::create( 'database\word', $a_obj->word_id );
                    $word_1 = $db_word->word;
                    $word_id_1 = $db_word->id;
                    $dictionary_id = $db_word->dictionary_id;
                    $classification_1 = array_key_exists( $dictionary_id, $classification ) ? 
                      $classification[ $dictionary_id ] : '';
                  }
                }
                else
                {
                  $rank = $a_obj->rank;

                  if( !( is_null( $a_obj->word_id ) && is_null( $b_obj->word_id ) ) )
                  {
                    $adjudicate = ( $a_obj->word_id != $b_obj->word_id ||
                                    $a_obj->selection != $b_obj->selection );

                    if( !is_null( $a_obj->word_id ) )
                    {
                      $db_word = lib::create( 'database\word', $a_obj->word_id );
                      $word_id_1 = $db_word->id;
                      $word_1 = $db_word->word;
                      $dictionary_id = $db_word->dictionary_id;
                      $classification_1 = array_key_exists( $dictionary_id, $classification ) ? 
                        $classification[ $dictionary_id ] : '';
                    }

                    if( !is_null( $b_obj->word_id ) )
                    {
                      $db_word = lib::create( 'database\word', $b_obj->word_id );
                      $word_id_2 = $db_word->id;
                      $word_2 = $db_word->word;
                      $dictionary_id = $db_word->dictionary_id;
                      $classification_2 = array_key_exists( $dictionary_id, $classification ) ? 
                        $classification[ $dictionary_id ] : '';
                    }
                  }
                }

                $adjudicate_data[] = array(
                         'id_1' => $id_1,
                         'id_2' => $id_2,
                         'selection_1' => $selection_1,
                         'selection_2' => $selection_2,
                         'word_id_1' => $word_id_1,
                         'word_1' => $word_1,
                         'classification_1' => $classification_1,
                         'word_id_2' => $word_id_2,
                         'word_2' => $word_2,
                         'classification_2' => $classification_2,
                         'adjudicate' => $adjudicate );

                next( $a );
                next( $b );
              }
            }
          }
        } 
      } 
    }

    if( is_null( $adjudicate_data ) )
      throw lib::create( 'exception\runtime', 
        'Adjudication is not required for one or more entries for the '. 
        $db_test_entry->get_test()->name . ' test pertaining to participant UID '. 
        $db_test_entry->get_assignment()->get_participant()->uid, __METHOD__ );

    return $adjudicate_data;
  }
}