<?
////////////////////
//
//  MARKS CHANGES
//
////////////////////
//This wordpress hook was called in C:\makeshift\files\wordpress\goon_city\wp-content\plugins\watupro\controllers\questions.php (function: watupro_questions, line 248)
//This is at the point where a question has been changed in the Administration Panel
add_action( 'watupro_saved_question', 'watupro_saved_question_custom');
function watupro_saved_question_custom($question_id)
{
	global $wpdb;
$_watu = new WatuPRO();
//From the question id, get the exam it's affiliated with (exam is a parent entity of the question)
//Left joining into the Exam table to get advanced settings
$exam = $wpdb->get_row($wpdb->prepare("SELECT questions.* , master.advanced_settings FROM " . WATUPRO_QUESTIONS." as questions LEFT JOIN ".WATUPRO_EXAMS." as master ON master.ID = questions.exam_id WHERE questions.ID=%d", $question_id));
$exam_id = $exam->exam_id;
//code from show_exam.php (before submit_exam.php)
$advanced_settings = unserialize(stripslashes($exam->advanced_settings));
//From the Student Taken Occurences Table (the answers that were chosen will be put here for comparison, to compare to the current answers located in the wp_watupro_answers table).
$takings = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".WATUPRO_TAKEN_EXAMS." WHERE exam_id=%d ORDER BY ID DESC", $exam_id));
//Get all the questions from the Exam Id
$questions = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".WATUPRO_QUESTIONS." WHERE exam_id=%d", $exam_id));
//Iterate through all student taking IDS, then iterate through the questions to calculate totals
foreach ($takings as $taking)
{
	$taking_id = $taking->ID;
	$total = $score = $max_points = $achieved = $num_empty = $num_wrong = 0;
	//Iterate through the questions to calculate totals
	foreach ($questions as $question) {	
		$question_id = $question->ID;
		//According to the store_result function in the watopro.php lib file, a $question object is a database row.
		$answers = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".WATUPRO_ANSWERS." WHERE question_id=%d AND question_id>0 ORDER BY sort_order", $question_id));
		//this part is necessary for the WTPQuestion::max_scores function. It's looking at this q_answers variable I just put below
		$question->q_answers = $answers;
		$chosen_answers = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".WATUPRO_STUDENT_ANSWERS." WHERE taking_id=%d AND question_id=%d", $taking_id,$question_id));
		//The entire purpose of getting $chosen_answer_ids is to put as a parameter to WTPQUESTION::calc_answer
		//THE FOLLOWING IS TO PROTECT the VALUES WHEN THERE ARE COMMAS
		$chosen_answer_ids = array();
		$answers_values = array_map(function($answer){return $answer->answer;},$answers);
		$answers_quotes = array_map(function($answer_value){return '"'. $answer_value . '"';},$answers_values);
		//unfortunately, you have to cross reference the answers the student chose with the correct answer known in the answers table (no join used here)
		foreach($chosen_answers as $chosen_answer)
		{
			//THIS IS THE ANNOYING LONG STRING WITH COMMAS!!!
			$chosen_answer_full_names = $chosen_answer->answer;
			$chosen_answer_full_names = str_replace($answers_values,$answers_quotes,$chosen_answer_full_names);
			foreach(str_getcsv($chosen_answer_full_names) as $chosen_answer_full_name)
			{
				$chosen_answer_full_name = trim($chosen_answer_full_name);
				foreach ($answers as $answer) {		
					if(strtolower($chosen_answer_full_name) == strtolower($answer->answer))
					{
						$chosen_answer_ids[] = $answer->ID;
					}
				}
			}
		}
		if($debug)
		{
			// echo "STUDENT ID: $taking_id\n";
			// debug_help("QUESTION",$question);
			// debug_help("ALL ANSWERS",$answers);
			// debug_help("CHOSEN ANSWER",$chosen_answers);
			// debug_help("CHOSEN ANSWER IDS",$chosen_answer_ids);
		}
		//THE POINT OF THOSE SQL CALLS WAS TO CALCULATE THE POINTS & A BOOLEAN IF THE QUESTION WAS CORRECT
		list($points, $correct, $is_empty) = WTPQuestion::calc_answer($question, $chosen_answer_ids,$answers);
		$question_max_points = WTPQuestion::max_points($question);
		//calculate total & score
		if(empty($question->is_survey)) $total++;
		if($correct) $score++;
		//calculate percent
	  	if($total==0) $percent=0;
		else $percent = number_format($score / $total * 100, 2);
		$percent = round($percent);
		//get total points that were acquired
		//the following is a separate grading system based on the amount of points, and not grading based on # right or # wrong. It is weighted.
		$achieved += $points;	
		$max_points += WTPQuestion::max_points($question);
		if($is_empty and empty($question->is_survey))
		{
			$num_empty++;
		}
      	if(!$is_empty and !$correct and empty($question->is_survey))
      	{
      		$num_wrong++;
      	}
		if($achieved <= 0 or $max_points <= 0)
		{
			$pointspercent = 0;
		}
		else
		{
			$pointspercent = number_format($achieved / $max_points * 100, 2);
		}
		//generic rating, doesn't matter as much as above
		$rating = $_watu->calculate_rating($total, $score, $percent);	
	}//end question iteration
	//Grade tabulating should not be in the question for-loop above, just as it is in submit-exam.php
	list($grade, $certificate_id, $do_redirect, $grade_obj) = WTPGrade::calculate($exam_id, $achieved, $percent, 0, $user_grade_ids, $pointspercent);
	$grade_value = $grade_obj->gtitle;
	if($debug)
	{
		echo "STUDENT TEST OCCURENCE: $taking_id\n";
		echo "POINTS: $points\n";
		echo "PERCENT: $percent\n";
		echo "SCORE: $score\n";
		echo "TOTAL: $total\n";
		echo "MAX POINTS: $max_points\n";
		echo "RATING: $rating\n";
		echo "ACHIEVED: $achieved\n";
		echo "POINTS PERCENT: $pointspercent\n";
		echo "GRADE: $grade\n";
		echo "GRADE: {$grade_obj->ID}\n";		
		echo "NUM EMPTY: $num_empty\n";
		echo "NUM WRONG: $num_wrong\n";
		echo "\n\n\n\n\n\n";
	}
	//points = all points combined ($achieved)
	//result = String HTML of Grade
	//grade_id = ID of Grade
	//percent_correct = 75 (based on right vs wrong)
	//percent_points = 82 (based on points) $pointpercent
	//num_correct = Number of Raw correct in entire exam (known as $score)
	//num_wrong = num_wrong
	//num_empty = num_empty
	//max_points = max_points
	$result = $wpdb->update(WATUPRO_TAKEN_EXAMS, array(
	    	"points" => $achieved,
			"result" => $grade,
			"grade_id" => $grade_obj->ID,
			"percent_correct" => $percent,
			"percent_points" => $pointspercent,
			"num_correct" => $score,
			"num_wrong" => $num_wrong,
			"num_empty" => $num_empty,
			"max_points" => $max_points
	    ),
		array(
			"id"=>$taking_id
		)	
	);
	echo "$result\n";
}//end taking exam occurence iteration


////////////////////
//
//  SECOND TWEAK (UPDATE THE OTHER TABLE AS WELL, WHICH IS THE WP_WATUPRO_STUDENT_PLUGIN APP. THE REASON FOR THIS IS TO UPDATE THE SINGLE PAGE VIEW OF THE EXAM TAKEN OCCURENCES, AS IT READS FROM THAT TABLE!!!
//
//////////////////// 
$student_answers = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".WATUPRO_STUDENT_ANSWERS." WHERE exam_id=%d ORDER BY ID DESC ",$exam_id));
foreach ($student_answers as $key => $student_answer) {
	$student_answer_question_id = $student_answer->question_id;
    $answers = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".WATUPRO_ANSWERS. " WHERE question_id=%d  AND question_id>0 ORDER BY sort_order",$student_answer_question_id));
    $question = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".WATUPRO_QUESTIONS. " WHERE ID=%d",$student_answer_question_id));
    $question->q_answers = $answers;
    $answers_values = array_map(function($answer){return $answer->answer;},$answers);
	$answers_quotes = array_map(function($answer_value){return '"'. $answer_value . '"';},$answers_values);	
	$chosen_answer_ids = array();
	$chosen_answer_full_names = $student_answer->answer;
	$chosen_answer_full_names = str_replace($answers_values,$answers_quotes,$chosen_answer_full_names);
	foreach(str_getcsv($chosen_answer_full_names) as $chosen_answer_full_name)
	{
		$chosen_answer_full_name = trim($chosen_answer_full_name);
		foreach ($answers as $answer) {		
			if(strtolower($chosen_answer_full_name) == strtolower($answer->answer))
			{
				$chosen_answer_ids[] = $answer->ID;
			}
		}
	}
	list($points, $correct, $is_empty) = WTPQuestion::calc_answer($question, $chosen_answer_ids,$answers);
	if($debug)
	{
		echo "-------\n";
		echo "POINTS: $points\n";
		echo "CORRECT: $correct\n";
		echo "IS EMPTY: $is_empty\n";
	}
	$result = $wpdb->update(WATUPRO_STUDENT_ANSWERS, array(
	    	"points" => $points,
			"is_correct" => $correct,
	    ),
		array(
			"ID"=>$student_answer->ID
		)
	);
	echo $result;
}
////////////////////
//
//  END CHANGING THE SINGLE VIEW SECTION IN THE WP_WATUPRO_STUDENT_PLUGIN
//
////////////////////
}