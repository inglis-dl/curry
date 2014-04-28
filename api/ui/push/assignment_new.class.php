<?php
/**
 * assignment_new.class.php
 * 
 * @author Dean Inglis <inglisd@mcmaster.ca>
 * @filesource
 */

namespace cedar\ui\push;
use cenozo\lib, cenozo\log, cedar\util;

/**
 * push: assignment new
 *
 * Create a new assignment.
 */
class assignment_new extends \cenozo\ui\push\base_new
{
  /**
   * Constructor.
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @param array $args Push arguments
   * @access public
   */
  public function __construct( $args )
  {
    parent::__construct( 'assignment', $args );
  }

  /** 
   * Processes arguments, preparing them for the operation.
   * 
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @access protected
   */
  protected function prepare()
  {
    $participant_class_name = lib::get_class_name( 'database\participant' );
    $assignment_class_name = lib::get_class_name( 'database\assignment' );

    $columns = $this->get_argument( 'columns', array() );
    if( empty( $columns ) )
    {  
      $this->arguments['columns'] = $columns;
    }
     
    if( ( !array_key_exists( 'user_id', $columns ) || 0 == strlen( $columns['user_id'] ) ) || 
        ( !array_key_exists( 'participant_id', $columns ) ||
          0 == strlen( $columns['participant_id'] ) ) )
    {
      $session = lib::create( 'business\session' );
      $db_role = $session->get_role();
      $db_user = $session->get_user();

      // filter on participants with cohorts this user is assigned to
      $cohort_ids = array();
      $cohort_list = $db_user->get_cohort_list();
      if( is_null( $cohort_list ) )
        throw lib::create( 'exception\notice',
          'There must be one or more cohorts assigned to user: '. $db_user->name,
            __METHOD__ );

      $has_tracking = false;
      $has_comprehensive = false;
      foreach( $cohort_list as $db_cohort )
      {
        $cohort_ids[] = $db_cohort->id;
        $has_tracking |= 'tracking' == $db_cohort->name;
        $has_comprehensive |= 'comprehensive' == $db_cohort->name;
      }

      $found = false;
      $participant_id = NULL;
      $cohort_name = '';

      // get participants assigned to one typist
      $pre_mod = lib::create( 'database\modifier' );
      $pre_mod->where( 'user_id', '!=', $db_user->id );
      $pre_mod->where( 'participant.active', '=', true );
      $pre_mod->where( 'participant.cohort_id', 'IN', $cohort_ids );      
      // filter on participants who have the same language as the user
      if( $db_user->language != 'any' )
      {
        $pre_mod->where( 'participant.language', '=', $db_user->language );
      }
      
      // block with a semaphore
      $session->acquire_semaphore();

      foreach( $assignment_class_name::select( $pre_mod ) as $db_assignment )
      {         
        $db_participant = $db_assignment->get_participant();
        // how many assignments have this participant id?
        $unique_mod = lib::create( 'database\modifier' );
        $unique_mod->where( 'participant_id', '=', $db_participant->id );
        if( 1 == $assignment_class_name::count( $unique_mod ) )
        {
          $participant_id = $db_participant->id;
          $cohort_name = $db_participant->get_cohort()->name;
          $found = true;
          break;
        }        
      }

      if( !$found )  
      {
        $base_mod = lib::create( 'database\modifier' );
        $base_mod->where( 'active', '=', true );
        $base_mod->where( 'cohort_id', 'IN', $cohort_ids );

        // filter on participants who have the same language as the user
        if( $db_user->language != 'any' )
        {
          $base_mod->where( 'language', '=', $db_user->language );
        }

        // the participant must have completed their interview
        $event_type_class_name = lib::get_class_name( 'database\event_type' );

        $db_tracking_event_type =
          $event_type_class_name::get_unique_record( 'name', 'completed (Baseline)' );

        $db_comprehensive_event_type =
          $event_type_class_name::get_unique_record( 'name', 'completed (Baseline Site)' );

        if( $has_tracking && $has_comprehensive )
        {
          $base_mod->where_bracket( true );
          // tracking
          $base_mod->where_bracket( true );
          $base_mod->where( 'event.event_type_id', '=', $db_tracking_event_type->id );
          $base_mod->where_bracket( false );
          // comprehensive
          $base_mod->where_bracket( true, true );
          $base_mod->where( 'event.event_type_id', '=', $db_comprehensive_event_type->id );
          $base_mod->where_bracket( false );
          $base_mod->where_bracket( false );
        }
        else if( $has_tracking )
        {
          $base_mod->where( 'event.event_type_id', '=', $db_tracking_event_type->id );
        }
        else if( $has_comprehensive )
        {
          $base_mod->where( 'event.event_type_id', '=', $db_comprehensive_event_type->id );
        }

        // exclude participants with two assignments
        $sql = 'SELECT participant_id FROM assignment '.
               'GROUP BY participant_id '.
               'HAVING COUNT(participant_id) = 2';
        $base_mod->where( 'participant.id', 'NOT IN', sprintf( '( %s )', $sql ) );

        $sabretooth_manager = NULL;
        if( $has_tracking )
        {
          $setting_manager = lib::create( 'business\setting_manager' );
          $sabretooth_manager = lib::create( 'business\cenozo_manager', SABRETOOTH_URL );
          $sabretooth_manager->set_user( $setting_manager->get_setting( 'sabretooth', 'user' ) );
          $sabretooth_manager->set_password( 
            $setting_manager->get_setting( 'sabretooth', 'password' ) );
          $sabretooth_manager->set_site( $setting_manager->get_setting( 'sabretooth', 'site' ) );
          $sabretooth_manager->set_role( $setting_manager->get_setting( 'sabretooth', 'role' ) );
        }

        $limit = 10;
        $offset = 0;
        $participant_count = 0;
        $max_try = 500;
        $try = 0;    
        
        do
        {
          $mod_limit = clone $base_mod;
          $mod_limit->limit( $limit, $offset );
          $participant_list = $participant_class_name::select( $mod_limit );
          $participant_count = count( $participant_list );
          if( 0 < $participant_count )
          {
            foreach( $participant_list as $db_participant )
            { 
              $db_assignment = $assignment_class_name::get_unique_record(
                array( 'user_id', 'participant_id' ),
                array( $db_user->id, $db_participant->id ) );
              if( is_null( $db_assignment ) )
              {
                $db_cohort = $db_participant->get_cohort();
                $assignment_mod = lib::create( 'database\modifier' );
                $assignment_mod->where( 'participant_id', '=', $db_participant->id );
                if( 2 > $assignment_class_name::count( $assignment_mod ) )
                {
                  // now see if this participant has any recordings
                  if( $has_tracking && $db_cohort->name == 'tracking' )
                  {
                    $args = array(
                      'qnaire_rank' => 1,
                      'participant_id' => $db_participant->id );
                    $recording_list = $sabretooth_manager->pull( 'recording', 'list', $args );
                    $recording_data = array();
                    if( !is_null( $recording_list) &&
                        1 == $recording_list->success && 0 < count( $recording_list->data ) )
                    {
                      $participant_id = $db_participant->id;
                      $cohort_name = 'tracking';
                      $found = true;
                      break;
                    }
                  }
                  else if( $has_comprehensive && $db_cohort->name == 'comprehensive' )
                  {
                    // stub until comprehensive recordings are worked out
                  } 
                }
              }
            }
            $offset += $limit;
          }
        } while( !$found && 0 < $participant_count && $max_try > $try++ );
      }

      $session->release_semaphore();

      // throw a notice if no participant was found
      if( !$found ) 
        throw lib::create( 'exception\notice',
          'There are currently no participants available for processing.', __METHOD__ );
       
      $columns['user_id'] = $db_user->id;
      $columns['participant_id'] = $participant_id;
      $columns['cohort_name'] = $cohort_name;
      $this->arguments['columns'] = $columns;
    }        
        
    parent::prepare();
  }

  /** 
   * Finishes the operation with any post-execution instructions that may be necessary.
   * 
   * @author Dean Inglis <inglisd@mcmaster.ca>
   * @throws exception\runtime
   * @access protected
   */
  protected function finish()
  {
    parent::finish();

    $db_assignment = $this->get_record();
    $columns = $this->get_argument( 'columns' );
    $test_class_name = lib::get_class_name( 'database\test' );

    $modifier = NULL; 
    if( $columns['cohort_name'] == 'tracking' )
    {
      $modifier = lib::create('database\modifier');
      $modifier->where( 'name', 'NOT LIKE', 'FAS%' );
    }  

    $language = $db_assignment->get_participant()->language;
    $language = is_null( $language ) ? 'en' : $language;
    $test_entry_class_name = lib::get_class_name( 'database\test_entry' );

    //create a test entry for each test
    foreach( $test_class_name::select( $modifier ) as $db_test )
    {
      $args = array();
      $args['columns']['test_id'] = $db_test->id;
      $args['columns']['assignment_id'] = $db_assignment->id;
      $operation = lib::create( 'ui\push\test_entry_new', $args );
      $operation->process();
    }
  }
}
