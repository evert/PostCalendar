<?php
/**
 * @package     PostCalendar
 * @author      $Author$
 * @link        $HeadURL$
 * @version     $Id$
 * @copyright   Copyright (c) 2002, The PostCalendar Team
 * @copyright   Copyright (c) 2009, Craig Heydenburg, Sound Web Development
 * @license     http://www.gnu.org/copyleft/gpl.html GNU General Public License
 */

include dirname(__FILE__) . '/global.php';

/**
 * This is the event handler api
 **/

/**
 * postcalendar_eventapi_queryEvents //new name
 * Returns an array containing the event's information (plural or singular?)
 * @param array $args arguments. Expected keys:
 *              eventstatus: -1 == hidden ; 0 == queued ; 1 == approved (default)
 *              start: Events start date (default today)
 *              end: Events end_date (default 000-00-00)
 *              s_keywords: search info
                filtercats: categories to query events from
 * @return array The events
 */
function postcalendar_eventapi_queryEvents($args)
{
    $dom = ZLanguage::getModuleDomain('PostCalendar');
    $end = '0000-00-00';
    extract($args); //start, end, s_keywords, filtercats, pc_username, eventstatus

    if (_SETTING_ALLOW_USER_CAL) { 
        $filterdefault = _PC_FILTER_ALL; 
    } else { 
        $filterdefault = _PC_FILTER_GLOBAL;
    }
    if (empty($pc_username)) $pc_username = $filterdefault;
    if (!pnUserLoggedIn()) $pc_username = _PC_FILTER_GLOBAL;

    $userid = pnUserGetVar('uid');
    unset($ruserid);

    // convert $pc_username to useable information
    /* possible values:
        _PC_FILTER_GLOBAL (-1)  = all public events
        _PC_FILTER_ALL (-2)     = all public events + my events
        _PC_FILTER_PRIVATE (-3) = just my private events
    */
    if ($pc_username > 0) {
        // possible values: a user id - only an admin can use this
        $ruserid = $pc_username; // keep the id
        $pc_username = _PC_FILTER_PRIVATE;
    } else {
        $ruserid = $userid; // use current user's ID
    }

    if (!isset($eventstatus) || ((int) $eventstatus < -1 || (int) $eventstatus > 1)) $eventstatus = 1;

    if (!isset($start)) $start = Date_Calc::dateNow('%Y-%m-%d');
    list($startyear, $startmonth, $startday) = explode('-', $start);

    $where = "WHERE pc_eventstatus=$eventstatus
              AND (pc_endDate>='$start' 
              OR (pc_endDate='0000-00-00' 
              AND pc_recurrtype<>'0') 
              OR pc_eventDate>='$start')
              AND pc_eventDate<='$end' ";

    // filter event display based on selection
    /* possible event sharing values @v5.8
    'SHARING_PRIVATE',       0);
    'SHARING_PUBLIC',        1); //remove in v6.0 - convert to SHARING_GLOBAL
    'SHARING_BUSY',          2); //remove in v6.0 - convert to SHARING_PRIVATE
    'SHARING_GLOBAL',        3);
    'SHARING_HIDEDESC',      4); //remove in v6.0 - convert to SHARING_PRIVATE
    */
    switch ($pc_username) {
        case _PC_FILTER_PRIVATE: // show just private events
            $where .= "AND pc_aid = $ruserid ";
            $where .= "AND (pc_sharing = '" . SHARING_PRIVATE . "' ";
            $where .= "OR pc_sharing = '" . SHARING_BUSY . "' "; //deprecated
            $where .= "OR pc_sharing = '" . SHARING_HIDEDESC . "') "; //deprecated
            break;
        case _PC_FILTER_ALL:  // show all public/global AND private events
            $where .= "AND (pc_aid = $ruserid ";
            $where .= "AND (pc_sharing = '" . SHARING_PRIVATE . "' ";
            $where .= "OR pc_sharing = '" . SHARING_BUSY . "' "; //deprecated
            $where .= "OR pc_sharing = '" . SHARING_HIDEDESC . "')) "; //deprecated
            $where .= "OR (pc_sharing = '" . SHARING_GLOBAL . "' ";
            $where .= "OR pc_sharing = '" . SHARING_PUBLIC . "') "; //deprecated
            break;
        case _PC_FILTER_GLOBAL: // show all public/global events
        default:
            $where .= "AND (pc_sharing = '" . SHARING_GLOBAL . "' ";
            $where .= "OR pc_sharing = '" . SHARING_PUBLIC . "') "; //deprecated
    }


    // convert categories array to proper filter info
    $catsarray = $filtercats['__CATEGORIES__'];
    foreach ($catsarray as $propname => $propid) {
        if ($propid <= 0) unset($catsarray[$propname]); // removes categories set to 'all' (0)
    }
    if (!empty($catsarray)) $catsarray['__META__']['module']="PostCalendar"; // required for search operation

    if (!empty($s_keywords)) $where .= "AND $s_keywords";

    $events = DBUtil::selectObjectArray('postcalendar_events', $where, null, null, null, null, null, $catsarray);

    return $events;
}

/**
 * This function returns an array of events sorted by date
 * expected args (from postcalendar_userapi_buildView): start, end
 *    if either is present, both must be present. else uses today's/jumped date.
 * expected args (from search/postcalendar_search_options): s_keywords, filtercats
 **/
function postcalendar_eventapi_getEvents($args)
{
    $dom = ZLanguage::getModuleDomain('PostCalendar');
    $s_keywords = ''; // search WHERE string
    extract($args); //start, end, filtercats, Date, s_keywords, pc_username

    $date  = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>$args['Date'])); //formats date

    if (!empty($s_keywords)) { unset($start); unset($end); } // clear start and end dates for search

    $currentyear  = substr($date, 0, 4);
    $currentmonth = substr($date, 4, 2);
    $currentday   = substr($date, 6, 2);

    if (isset($start) && isset($end)) {
        // parse start date
        list($startmonth, $startday, $startyear) = explode('/', $start);
        // parse end date
        list($endmonth, $endday, $endyear) = explode('/', $end);

        $s = (int) "$startyear$startmonth$startday";
        if ($s > $date) {
            $currentyear  = $startyear;
            $currentmonth = $startmonth;
            $currentday   = $startday;
        }
        $start_date = Date_Calc::dateFormat($startday, $startmonth, $startyear, '%Y-%m-%d');
        $end_date = Date_Calc::dateFormat($endday, $endmonth, $endyear, '%Y-%m-%d');
    } else {
        $startmonth = $endmonth = $currentmonth;
        $startday = $endday = $currentday;
        $startyear = $currentyear;
        $endyear = $currentyear + 2; // defaults to two-year span
        $start_date = $startyear . '-' . $startmonth . '-' . $startday;
        $end_date = $endyear . '-' . $endmonth . '-' . $endday;
    }

    if (!isset($s_keywords)) $s_keywords = '';
    $events = pnModAPIFunc('PostCalendar', 'event', 'queryEvents', 
        array('start'=>$start_date, 'end'=>$end_date, 's_keywords'=>$s_keywords, 
              'filtercats'=>$filtercats, 'pc_username'=>$pc_username));

    //==============================================================
    // Here an array is built consisting of the date ranges
    // specific to the current view.  This array is then
    // used to build the calendar display.
    //==============================================================
    $days = array();
    $sday = Date_Calc::dateToDays($startday, $startmonth, $startyear);
    $eday = Date_Calc::dateToDays($endday, $endmonth, $endyear);
    for ($cday = $sday; $cday <= $eday; $cday++) {
        $d = Date_Calc::daysToDate($cday, '%d');
        $m = Date_Calc::daysToDate($cday, '%m');
        $y = Date_Calc::daysToDate($cday, '%Y');
        $store_date = Date_Calc::dateFormat($d, $m, $y, '%Y-%m-%d');
        $days[$store_date] = array();
    }

    foreach ($events as $event) {
        $event = pnModAPIFunc('PostCalendar', 'event', 'formateventarrayfordisplay', $event);

        // should this bit be moved to queryEvents? shouldn't this be accomplished other ways? via DB?
        if ($event['sharing'] == SHARING_PRIVATE && $event['aid'] != pnUserGetVar('uid') && !SecurityUtil::checkPermission('PostCalendar::', '::', ACCESS_ADMIN)) {
            // if event is PRIVATE and user is not assigned event ID (aid) and user is not Admin event should not be seen
            continue;
        }

        // check the current event's permissions
        // the user does not have permission to view this event
        // if any of the following evaluate as false
        // can this information be filtered in the DBUtil call? - yes using permFilter see DBUtil CAH 11/30/09
        if (!SecurityUtil::checkPermission('PostCalendar::Event', "{$event['title']}::{$event['eid']}", ACCESS_OVERVIEW)) {
            continue;
        /*} elseif (!SecurityUtil::checkPermission('PostCalendar::Category', "$event[catname]::$event[catid]", ACCESS_OVERVIEW)) {
            continue;*/
        } elseif (!SecurityUtil::checkPermission('PostCalendar::User', "{$event[uname]}::{$event['aid']}", ACCESS_OVERVIEW)) {
            continue;
        }
        // split the event start date
        list($eventstartyear, $eventstartmonth, $eventstartday) = explode('-', $event['eventDate']);

        // determine the stop date for this event
        if ($event['endDate'] == '0000-00-00') {
            $stop = $end_date;
        } else {
            $stop = $event['endDate'];
        }

        // this switch block fills the $days array with events. It computes recurring events and adds the recurrances to the $days array also
        switch ($event['recurrtype']) {
            // Events that do not repeat only have a startday (eventDate)
            case NO_REPEAT:
                if (isset($days[$event['eventDate']])) {
                    $days[$event['eventDate']][] = $event;
                }
                break;
            case REPEAT:
                $rfreq = $event['repeat']['event_repeat_freq']; // could be any int
                $rtype = $event['repeat']['event_repeat_freq_type']; // REPEAT_EVERY_DAY (0), REPEAT_EVERY_WEEK (1), REPEAT_EVERY_MONTH (2), REPEAT_EVERY_YEAR (3)
                // we should bring the event up to date to make this a tad bit faster
                // any ideas on how to do that, exactly??? dateToDays probably. (RNG <5.0)
                $newyear  = $eventstartyear;
                $newmonth = $eventstartmonth;
                $newday   = $eventstartday;
                $occurance = Date_Calc::dateFormat($newday, $newmonth, $newyear, '%Y-%m-%d');
                while ($occurance < $start_date) {
                    $occurance = dateIncrement($newday, $newmonth, $newyear, $rfreq, $rtype);
                    list($newyear, $newmonth, $newday) = explode('-', $occurance);
                }
                while ($occurance <= $stop) {
                    if (isset($days[$occurance])) {
                        $days[$occurance][] = $event;
                    }
                    $occurance = dateIncrement($newday, $newmonth, $newyear, $rfreq, $rtype);
                    list($newyear, $newmonth, $newday) = explode('-', $occurance);
                }
                break;
            case REPEAT_ON:
                $rfreq = $event['repeat']['event_repeat_on_freq']; // could be any int
                $rnum  = $event['repeat']['event_repeat_on_num']; // REPEAT_ON_1ST (1), REPEAT_ON_2ND (2), REPEAT_ON_3RD (3), REPEAT_ON_4TH (4), REPEAT_ON_LAST(5)
                $rday  = $event['repeat']['event_repeat_on_day']; // REPEAT_ON_SUN (0), REPEAT_ON_MON (1), REPEAT_ON_TUE (2), REPEAT_ON_WED (3), REPEAT_ON_THU(4), REPEAT_ON_FRI (5), REPEAT_ON_SAT (6)
                $newmonth = $eventstartmonth;
                $newyear  = $eventstartyear;
                $newday   = $eventstartday;
                // make us current
                while ($newyear < $currentyear) {
                    $occurance = date('Y-m-d', mktime(0, 0, 0, $newmonth + $rfreq, $newday, $newyear));
                    list($newyear, $newmonth, $newday) = explode('-', $occurance);
                }
                // populate the event array
                while ($newyear <= $currentyear) {
                    $dnum = $rnum; // get day event repeats on
                    do {
                        $occurance = Date_Calc::NWeekdayOfMonth($dnum--, $rday, $newmonth, $newyear, "%Y-%m-%d");
                    } while ($occurance === -1);
                    if (isset($days[$occurance]) && $occurance <= $stop) {
                        $days[$occurance][] = $event;
                    }
                    $occurance = date('Y-m-d', mktime(0, 0, 0, $newmonth + $rfreq, $newday, $newyear));
                    list($newyear, $newmonth, $newday) = explode('-', $occurance);
                }
                break;
        } // <- end of switch($event['recurrtype'])
    } // <- end of foreach($events as $event)
    return $days;
}

/**
 * postcalendar_eventapi_writeEvent()
 * write an event to the DB
 * @param $args array of event data
 * @return bool true on success : false on failure;
 */
function postcalendar_eventapi_writeEvent($args)
{
    $eventdata = $args['eventdata'];
    if (!isset($eventdata['is_update'])) $eventdata['is_update'] = false;

    if ($eventdata['is_update']) {
        unset($eventdata['is_update']);
        $obj = array($eventdata['eid'] => $eventdata);
        $result = DBUtil::updateObjectArray($obj, 'postcalendar_events', 'eid');
    } else { //new event
        unset($eventdata['eid']); //be sure that eid is not set on insert op to autoincrement value
        unset($eventdata['is_update']);
        $eventdata['time'] = date("Y-m-d H:i:s"); //current date
        $result = DBUtil::insertObject($eventdata, 'postcalendar_events', 'eid');
    }
    if ($result === false) return false;

    return $result['eid'];
}

/**
 * postcalendar_eventapi_buildSubmitForm()
 * generate information to help build the submit form
 * this is also used on a preview of event function, so $eventdata is passed from that if 'loaded'
 * args: 'eventdata','Date'
 * @return $form_data (array) key, val pairs to be assigned to the template, including default event data
 */
function postcalendar_eventapi_buildSubmitForm($args)
{
    $dom = ZLanguage::getModuleDomain('PostCalendar');

    $eventdata = $args['eventdata']; // contains data for editing if loaded

    // format date information 
    if (($eventdata['endDate'] == '') || ($eventdata['endDate'] == '00000000') || ($eventdata['endDate'] == '0000-00-00')) {
        $eventdata['endvalue'] = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>$args['Date'], 'format'=>_SETTING_DATE_FORMAT));
        $eventdata['endDate'] = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>$args['Date'], 'format'=>'%Y-%m-%d')); // format for JS cal & DB
    }  else {
        $eventdata['endvalue'] = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>str_replace('-', '', $eventdata['endDate']), 'format'=>_SETTING_DATE_FORMAT));
    }
    if ($eventdata['eventDate'] == '') {
        $eventdata['eventDatevalue'] = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>$args['Date'], 'format'=>_SETTING_DATE_FORMAT));
        $eventdata['eventDate'] = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>$args['Date'], 'format'=>'%Y-%m-%d')); // format for JS cal & DB
    } else {
        $eventdata['eventDatevalue'] = pnModAPIFunc('PostCalendar','user','getDate',array('Date'=>str_replace('-', '', $eventdata['eventDate']), 'format'=>_SETTING_DATE_FORMAT));
    }

    if ((SecurityUtil::checkPermission('PostCalendar::', '::', ACCESS_ADMIN)) && (_SETTING_ALLOW_USER_CAL)) {
        $users = DBUtil::selectFieldArray('users', 'uname', null, null, null, 'uid');
        $form_data['users'] = $users;
    }
    $eventdata['aid'] = $eventdata['aid'] ? $eventdata['aid'] : pnUserGetVar('uid'); // set value of user-select box
    $form_data['username_selected'] = pnUsergetVar('uname', $eventdata['aid']); // for display of username

    // load the category registry util
    if (!Loader::loadClass('CategoryRegistryUtil')) {
        pn_exit(__f('Error! Unable to load class [%s]', 'CategoryRegistryUtil'));
    }
    $form_data['catregistry'] = CategoryRegistryUtil::getRegisteredModuleCategories('PostCalendar', 'postcalendar_events');
    $form_data['cat_count'] = count($form_data['catregistry']);
    // configure default categories
    $eventdata['__CATEGORIES__'] = $eventdata['__CATEGORIES__'] ? $eventdata['__CATEGORIES__'] : pnModGetVar('PostCalendar', 'pcDefaultCategories');

    // All-day event values for radio buttons
    $form_data['SelectedAllday'] = $eventdata['alldayevent'] == 1 ? ' checked' : '';
    $form_data['SelectedTimed'] = (($eventdata['alldayevent'] == 0) OR (!isset($eventdata['alldayevent']))) ? ' checked' : ''; //default

    // StartTime
    $form_data['minute_interval'] = _SETTING_TIME_INCREMENT;
    if (empty($eventdata['startTime'])) $eventdata['startTime'] = '01:00:00'; // default to 1:00 AM

    // duration
    if (empty($eventdata['duration'])) $eventdata['duration'] = '1:00'; // default to 1:00 hours

    // hometext
    if (empty($eventdata['HTMLorTextVal'])) $eventdata['HTMLorTextVal'] = 'text'; // default to text

    // create html/text selectbox
    $form_data['EventHTMLorText'] = array('text' => __('Plain text', $dom), 'html' => __('HTML-formatted', $dom));

    // create sharing selectbox
    $data = array();
    if (_SETTING_ALLOW_USER_CAL) $data[SHARING_PRIVATE]=__('Private', $dom);
    if (SecurityUtil::checkPermission('PostCalendar::', '::', ACCESS_ADMIN) || _SETTING_ALLOW_GLOBAL || !_SETTING_ALLOW_USER_CAL) {
        $data[SHARING_GLOBAL]=__('Global', $dom);
    }
    $form_data['sharingselect'] = $data;

    if (!isset($eventdata['sharing'])) $eventdata['sharing'] = SHARING_GLOBAL; //default

    // recur type radio selects
    $form_data['SelectedNoRepeat'] = (((int) $eventdata['recurrtype'] == 0) OR (empty($eventdata['recurrtype']))) ? ' checked' : ''; //default
    $form_data['SelectedRepeat'] = (int) $eventdata['recurrtype'] == 1 ? ' checked' : '';
    $form_data['SelectedRepeatOn'] = (int) $eventdata['recurrtype'] == 2 ? ' checked' : '';

    // recur select box arrays
    $in = explode ("/", __('Day(s)/Week(s)/Month(s)/Year(s)', $dom));
    $keys = array(REPEAT_EVERY_DAY, REPEAT_EVERY_WEEK, REPEAT_EVERY_MONTH, REPEAT_EVERY_YEAR);
    $selectarray = array_combine($keys, $in);
    $form_data['repeat_freq_type'] = $selectarray;

    $in = explode ("/", __('First/Second/Third/Fourth/Last', $dom));
    $keys = array(REPEAT_ON_1ST, REPEAT_ON_2ND, REPEAT_ON_3RD, REPEAT_ON_4TH, REPEAT_ON_LAST);
    $selectarray = array_combine($keys, $in);
    $form_data['repeat_on_num'] = $selectarray;

    $in = explode (" ", __('Sun Mon Tue Wed Thu Fri Sat', $dom));
    $keys = array(REPEAT_ON_SUN, REPEAT_ON_MON, REPEAT_ON_TUE, REPEAT_ON_WED, REPEAT_ON_THU, REPEAT_ON_FRI, REPEAT_ON_SAT);
    $selectarray = array_combine($keys, $in);
    $form_data['repeat_on_day'] = $selectarray;

     // recur defaults
    if (empty($eventdata['repeat']['event_repeat_freq_type']) || $eventdata['repeat']['event_repeat_freq_type'] < 1) $eventdata['repeat']['event_repeat_freq_type'] = REPEAT_EVERY_DAY;
    if (empty($eventdata['repeat']['event_repeat_on_num']) || $eventdata['repeat']['event_repeat_on_num'] < 1) $eventdata['repeat']['event_repeat_on_num'] = REPEAT_ON_1ST;
    if (empty($eventdata['repeat']['event_repeat_on_day']) || $eventdata['repeat']['event_repeat_on_day'] < 1) $eventdata['repeat']['event_repeat_on_day'] = REPEAT_ON_SUN;

    // endType
    $form_data['SelectedEndOn'] = (int) $eventdata['endtype'] == 1 ? ' checked' : '';
    $form_data['SelectedNoEnd'] = (((int) $eventdata['endtype'] == 0) OR (empty($eventdata['endtype']))) ? ' checked' : ''; //default

    // Assign the content format (determines if scribite is in use)
    $form_data['formattedcontent'] = pnModAPIFunc('PostCalendar', 'event', 'isformatted', array('func' => 'new'));

    // assign loaded data or default values
    $form_data['loaded_event'] = DataUtil::formatForDisplay($eventdata);

    return $form_data;
}

/**
 * @function    postcalendar_eventapi_formateventarrayfordisplay()
 * @description This function reformats the information in an event array for proper display in detail
 * @args        $event (array) event array as pulled from the DB
 * @author      Craig Heydenburg
 *
 * @return      $event (array) modified array for display
 */
function postcalendar_eventapi_formateventarrayfordisplay($event)
{
    $dom = ZLanguage::getModuleDomain('PostCalendar');
    if ((empty($event)) or (!is_array($event))) return LogUtil::registerError(__('Required argument not present.', $dom));

    //remap sharing values to global/private (this sharing map converts pre-6.0 values to 6.0+ values)
    $sharingmap = array(SHARING_PRIVATE=>SHARING_PRIVATE, SHARING_PUBLIC=>SHARING_GLOBAL, SHARING_BUSY=>SHARING_PRIVATE, SHARING_GLOBAL=>SHARING_GLOBAL, SHARING_HIDEDESC=>SHARING_PRIVATE);
    $event['sharing'] = $sharingmap[$event['sharing']];

    // prep hometext for display
    if ($event['hometext'] == 'n/a') $event['hometext'] = ':text:n/a'; // compenseate for my bad programming in previous versions CAH
    $event['HTMLorTextVal'] = substr($event['hometext'], 1, 4); // HTMLorTextVal needed in edit form
    $event['hometext'] = substr($event['hometext'], 6);
    if ($event['HTMLorTextVal'] == "text") $event['hometext']  = nl2br(strip_tags($event['hometext']));

    // add unserialized info to event array
    $event['location_info'] = DataUtil::is_serialized($event['location']) ? unserialize($event['location']) : $event['location']; //on preview of formdata, location is not serialized
    $event['repeat']        = unserialize($event['recurrspec']);

    // build recurrance sentence for display
    $repeat_freq_type = explode ("/", __('Day(s)/Week(s)/Month(s)/Year(s)', $dom));
    $repeat_on_num    = explode ("/", __('First/Second/Third/Fourth/Last', $dom));
    $repeat_on_day    = explode (" ", __('Sun Mon Tue Wed Thu Fri Sat', $dom));
    if ($event['recurrtype'] == REPEAT) {
        $event['recurr_sentence']  = __f("Event recurs every %s", $event['repeat']['event_repeat_freq'], $dom);
        $event['recurr_sentence'] .= " ".$repeat_freq_type[$event['repeat']['event_repeat_freq_type']];
        $event['recurr_sentence'] .= " ".__("until", $dom)." ".$event['endDate'];
    } elseif ($event['recurrtype'] == REPEAT_ON) {
        $event['recurr_sentence']  = __("Event recurs on", $dom)." ".$repeat_on_num[$event['repeat']['event_repeat_on_num']];
        $event['recurr_sentence'] .= " ".$repeat_on_day[$event['repeat']['event_repeat_on_day']];
        $event['recurr_sentence'] .= " ".__f("of the month, every %s months", $event['repeat']['event_repeat_on_freq'], $dom);
        $event['recurr_sentence'] .= " ".__("until", $dom)." ".$event['endDate'];
    } else {
        $event['recurr_sentence']  = __("This event does not recur.", $dom);
    }

    // build sharing sentence for display
    $event['sharing_sentence'] = ($event['sharing'] == SHARING_PRIVATE) ? __('This is a private event.', $dom) : __('This is a public event. ', $dom);

    // converts seconds to HH:MM for display
    $event['duration'] = gmdate("G:i", $event['duration']);

    // format endtype for edit form
    $event['endtype'] = $event['endDate'] == '0000-00-00' ? '0' : '1';

    // compensate for changeover to new categories system
    $lang = ZLanguage::getLanguageCode();
    $event['catname']  = $event['__CATEGORIES__']['Main']['display_name'][$lang];
    $event['catcolor'] = $event['__CATEGORIES__']['Main']['__ATTRIBUTES__']['color'];
    $event['cattextcolor'] = postcalendar_eventapi_color_inverse($event['catcolor']);

    // temporarily remove hometext from array
    $hometext = $event['hometext'];
    unset ($event['hometext']);
    // format all the values for display
    $event = DataUtil::formatForDisplay($event);
    $event['hometext'] = DataUtil::formatForDisplayHTML($hometext); //add hometext back into array with HTML formatting

    return $event;
}

/**
 * @function    postcalendar_eventapi_formateventarrayforDB()
 * @description This function reformats the information in an event array for insert/update in DB
 * @args        $event (array) event array as pulled from the new/edit event form
 * @author      Craig Heydenburg
 *
 * @return      $event (array) modified array for DB insert/update
 */
function postcalendar_eventapi_formateventarrayforDB($event)
{
    if (substr($event['endDate'], 0, 4) == '0000') $event['endDate'] = $event['eventDate'];

    // reformat times from form to 'real' 24-hour format
    $event['duration'] = (60 * 60 * $event['duration']['Hour']) + (60 * $event['duration']['Minute']);
    if ((bool) !_SETTING_TIME_24HOUR) {
        if ($event['startTime']['Meridian'] == "am") {
            $event['startTime']['Hour'] = $event['startTime']['Hour'] == 12 ? '00' : $event['startTime']['Hour'];
        } else {
            $event['startTime']['Hour'] = $event['startTime']['Hour'] != 12 ? $event['startTime']['Hour'] += 12 : $event['startTime']['Hour'];
        }
    }
    $startTime = sprintf('%02d', $event['startTime']['Hour']) .':'. sprintf('%02d', $event['startTime']['Minute']) .':00';
    unset($event['startTime']); // clears the whole array
    $event['startTime'] = $startTime;
    // if event ADD perms are given to anonymous users...
    if (pnUserLoggedIn()) {
        $event['informant'] = SessionUtil::getVar('uid');
    } else {
        $event['informant'] = 1; // 'guest'
    }

    define('PC_ACCESS_ADMIN', SecurityUtil::checkPermission('PostCalendar::', '::', ACCESS_ADMIN));

    // determine if the event is to be published immediately or not
    if ((bool) _SETTING_DIRECT_SUBMIT || (bool) PC_ACCESS_ADMIN || ($event_sharing != SHARING_GLOBAL)) {
        $event['eventstatus'] = _EVENT_APPROVED;
    } else {
        $event['eventstatus'] = _EVENT_QUEUED;
    }

    // format some vars for the insert statement
    $event['endDate'] = $event['endtype'] == 1 ? $event['endDate'] : '0000-00-00';

    if (!isset($event['alldayevent'])) $event['alldayevent'] = 0;

    $dom = ZLanguage::getModuleDomain('PostCalendar');
    if (empty($event['hometext'])) {
        $event['hometext'] = ':text:' . __(/*!(abbr) not applicable or not available*/'n/a', $dom); // default description
    } else {
        $event['hometext'] = ':'. $event['html_or_text'] .':'. $event['hometext']; // inserts :text:/:html: before actual content
    }

    $event['location'] = serialize($event['location']);
    if (!isset($event['recurrtype'])) $event['recurrtype'] = NO_REPEAT;
    $event['recurrspec'] = serialize($event['repeat']);

    return $event;
}

/**
 * @function    postcalendar_eventapi_validateformdata()
 * @description This function validates the data that has been submitted in the new/edit event form
 * @args        $submitted_event (array) event array as submitted
 * @author      Craig Heydenburg
 *
 * @return      $abort (bool) default=false. true if data does not validate.
 */
function postcalendar_eventapi_validateformdata($submitted_event)
{
    $dom = ZLanguage::getModuleDomain('PostCalendar');

    if (empty($submitted_event['title'])) {
        LogUtil::registerError(__(/*!This is the field name from pntemplates/event/postcalendar_event_submit.htm:22*/"'Title' is a required field.", $dom).'<br />');
        return true;
    }

    // check repeating frequencies
    if ($submitted_event['recurrtype'] == REPEAT) {
        if (!isset($submitted_event['repeat']['event_repeat_freq']) || $submitted_event['repeat']['event_repeat_freq'] < 1 || empty($submitted_event['repeat']['event_repeat_freq'])) {
            LogUtil::registerError(__('Error! The repetition frequency must be at least 1.', $dom));
            return true;
        } elseif (!is_numeric($submitted_event['repeat']['event_repeat_freq'])) {
            LogUtil::registerError(__('Error! The repetition frequency must be an integer.', $dom));
            return true;
        }
    } elseif ($submitted_event['recurrtype'] == REPEAT_ON) {
        if (!isset($submitted_event['repeat']['event_repeat_on_freq']) || $submitted_event['repeat']['event_repeat_on_freq'] < 1 || empty($submitted_event['repeat']['event_repeat_on_freq'])) {
            LogUtil::registerError(__('Error! The repetition frequency must be at least 1.', $dom));
            return true;
        } elseif (!is_numeric($submitted_event['repeat']['event_repeat_on_freq'])) {
            LogUtil::registerError(__('Error! The repetition frequency must be an integer.', $dom));
            return true;
        }
    }

    // check date validity
    $sdate = strtotime($submitted_event['eventDate']);
    $edate = strtotime($submitted_event['endDate']);
    $tdate = strtotime(date('Y-m-d'));

    if (($submitted_event['endtype'] == 1) && ($edate < $sdate)) {
        LogUtil::registerError(__('Error! The selected start date falls after the selected end date.', $dom));
        return true;
    }
    /*
    if (!checkdate($submitted_event['eventDate']['month'], $submitted_event['eventDate']['day'], $submitted_event['eventDate']['year'])) {
        LogUtil::registerError(__('Error! Invalid start date.', $dom));
        $abort = true;
    }
    if (!checkdate($submitted_event['endDate']['month'], $submitted_event['endDate']['day'], $submitted_event['endDate']['year'])) {
        LogUtil::registerError(__('Error! Invalid end date.', $dom));
        $abort = true;
    }
    */
    return false;
}

/**
 * makeValidURL()
 * returns 'improved' url based on input string
 * checks to make sure scheme is present
 * @private
 * @returns string
 */
if (!function_exists('makeValidURL')) { // also defined in pnadminapi.php
    function makeValidURL($s)
    {
        if (empty($s)) return '';
        if (!preg_match('|^http[s]?:\/\/|i', $s)) $s = 'http://' . $s;
        return $s;
    }
}

/**
 * dateIncrement()
 * returns the next valid date for an event based on the
 * current day,month,year,freq and type
 * @private
 * @returns string YYYY-MM-DD
 */
function dateIncrement($d, $m, $y, $f, $t)
{
    if ($t == REPEAT_EVERY_DAY) {
        return date('Y-m-d', mktime(0, 0, 0, $m, ($d + $f), $y));
    } elseif ($t == REPEAT_EVERY_WEEK) {
        return date('Y-m-d', mktime(0, 0, 0, $m, ($d + (7 * $f)), $y));
    } elseif ($t == REPEAT_EVERY_MONTH) {
        return date('Y-m-d', mktime(0, 0, 0, ($m + $f), $d, $y));
    } elseif ($t == REPEAT_EVERY_YEAR) {
        return date('Y-m-d', mktime(0, 0, 0, $m, $d, ($y + $f)));
    }
}

/**
 * postcalendar_eventapi_isformatted
 * This function is copied directly from the News module
 * credits to Jorn Wildt, Mark West, Philipp Niethammer or whoever wrote it
 *
 * @purpose analyze if the module has an Scribite! editor assigned
 * @param string func the function to check
 * @return bool
 * @access public
 */
function postcalendar_eventapi_isformatted($args)
{
    if (!isset($args['func'])) {
        $args['func'] = 'all';
    }

    if (pnModAvailable('scribite')) {
        $modinfo = pnModGetInfo(pnModGetIDFromName('scribite'));
        if (version_compare($modinfo['version'], '2.2', '>=')) {
            $apiargs = array('modulename' => 'PostCalendar'); // parameter handling corrected in 2.2
        } else {
            $apiargs = 'PostCalendar'; // old direct parameter
        }

        $modconfig = pnModAPIFunc('scribite', 'user', 'getModuleConfig', $apiargs);
        if (in_array($args['func'], (array)$modconfig['modfuncs']) && $modconfig['modeditor'] != '-') {
            return true;
        }
    }
    return false;
}

/**
 * @description generate the inverse color
 * @author      Jonas John
 * @link        http://www.jonasjohn.de/snippets/php/color-inverse.htm
 * @license     public domain
 * @created     06/13/2006
 * @params      (string) hex color (e.g. #ffffff)
 * @return      (string) hex color (e.g. #000000)
 **/
function postcalendar_eventapi_color_inverse($color)
{
    $color = str_replace('#', '', $color);
    if (strlen($color) != 6){ return '000000'; }
    $rgb = '';
    for ($x=0;$x<3;$x++){
        $c = 255 - hexdec(substr($color,(2*$x),2));
        $c = ($c < 0) ? 0 : dechex($c);
        $rgb .= (strlen($c) < 2) ? '0'.$c : $c;
    }
    return '#'.$rgb;
}