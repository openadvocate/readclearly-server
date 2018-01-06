<?php


/**
 * Loads a glossary from either a local file or GitHub.
 */
function load_glossary($glossary_name) {
  $glossary_file = FALSE;
  $cache_lifetime = 3600;

  // Check if glossary selection is provided. Try to load from local file first,
  // and from GitHub if no local file is found.
  if ($glossary_name) {
    $glossary_name = preg_replace('@[^a-zA-Z0-9_-]@', '_', $glossary_name);

    if (file_exists($glossary_name . '.csv')) {
      // If there is a local file with the given name, that takes precedence.
      $glossary_file = $glossary_name . '.csv';
    }
    else {
      // Check glossaries on GitHub.
      $github_base = 'https://raw.githubusercontent.com/openadvocate/readclearly/master/';
      $glossary_file = 'cache/' . $glossary_name . '.csv';

      if (!file_exists($glossary_file) || filemtime($glossary_file) < time() - $cache_lifetime) {
        $github_url = $github_base . $glossary_name . '/glossary.csv';
        $glossary_content = @file_get_contents($github_url);

        if (!empty($glossary_content)) {
          // Cache the file.
          file_put_contents($glossary_file, $glossary_content);
        }
      }

      if (!file_exists($glossary_file)) {
        // Invalid glossary name provided, or glossary failed to download from GitHub.
        $glossary_name = FALSE;
        $glossary_file = FALSE;
      }
    }
  }

  if (!$glossary_name) {
    // Use default name in the predefined list.
    $glossary_fp = fopen('glossary_list.csv', 'r');
    $glossary_line = fgetcsv($glossary_fp, 1000, ',');

    if (!empty($glossary_line[0]) && file_exists($glossary_line[0] . '.csv')) {
      $glossary_name = $glossary_line[0];
      $glossary_file = $glossary_line[0] . '.csv';
    }
  }

  if (!$glossary_file) {
    return;
  }

  $glossary = array();
  $glossary_spanish = array();
  $glossary_handle = fopen($glossary_file, 'r');
  $i = 0;
  while (($data = fgetcsv($glossary_handle, 2000, ',')) !== FALSE) {
    $i++;
    if ($i == 1) {
      // First line is the header.
      continue;
    }

    if (empty($data[1])) {
      continue;
    }

    $word = trim(str_replace(array("\n", "\r", '#'), '', $data[0]));
    $explanation = trim($data[1]);
    $explanation = trim($explanation, ',');

    // Quotes in the text are escaped.
    $explanation = str_replace('""', '"', $explanation);

    // Third column may be Spanish translation.
    if (!empty($data[2])) {
      $glossary_spanish[strtolower($word)] = array(
        trim($data[2]), // Spanish description
        !empty($data[3]) ? trim($data[3]) : '', // Spanish word
      );
    }

    $glossary[strtolower($word)] = $explanation;
  }

  // Order words by length.
  uksort($glossary, 'length_sort_compare');

  return array(
    'glossary' => $glossary,
    'glossary_spanish' => $glossary_spanish,
    'file' => $glossary_file,
  );
}

/**
 * Processes the user-submitted content.
 */
function process_input($content, $glossary_data) {
  // Scan content for keywords.
  $matched_words = array();
  $new_words = array();

  foreach ($glossary_data['glossary'] as $word => $explanation) {
    $count = 0;

    // Offer Spanish translation, if available.
    $exp_spanish = '';
    if (isset($glossary_data['glossary_spanish'][$word])) {
      $exp_spanish = 'data-glossary-es-desc="' . htmlspecialchars($glossary_data['glossary_spanish'][$word][0]) . '" data-glossary-es="' . htmlspecialchars($glossary_data['glossary_spanish'][$word][1]) . '"';
    }

    // The regex matches the full word outside of tags unless the next tag is <span class="oarc-marker">, meaning it's in between oarc tags already.
    // span.oarc-marker is needed for the regex so that it can identify the end of a marked word with certainty.
    // The / and . in the lookbehind is added to avoid words inside urls.
    $content = preg_replace('@(?<![\w/.])(' . preg_quote($word, '@') . ')(?![\w/]|[^<]*<span class="oarc-marker">|[^<]*>)@i',
      '<span class="oarc-word" data-glossary="' . htmlspecialchars($explanation) . '" ' . $exp_spanish . '>${1}<span class="oarc-marker"></span></span>',
      $content, -1, $count);

    if ($count) {
      if (!isset($matched_words[$word])) {
        $matched_words[$word] = 0;
      }

      $matched_words[$word] += $count;
    }
  }

  // Identify unmatched words for logging.
  // Remove matches.
  $unmatched = preg_replace('@<span class="oarc-word".+?<span class="oarc-marker"></span></span>@', ' ', $content);
  // Remove the 'show glossary' button, in case it's in the content.
  $unmatched = preg_replace('@<div id="oarc-activate".+?</div>@', ' ', $unmatched);

  // Find words.
  $unmatched = strip_tags($unmatched);
  $unmatched = preg_replace('@[^a-z0-9&+-]@i', ' ', $unmatched);
  $words = explode(' ', $unmatched);

  foreach ($words as $word) {
    // Drop too short words.
    if (strlen($word) < 6) {
      continue;
    }

    $word = strtolower($word);

    if (!in_array($word, array_keys($matched_words))) {
      if (!isset($new_words[$word])) {
        $new_words[$word] = 0;
      }

      $new_words[$word]++;
    }
  }

  // Logging
  oarc_log_run();
  oarc_log_words($matched_words, 'oarc_matched', $glossary_data['file']);
  oarc_log_words($new_words, 'oarc_unmatched', $glossary_data['file']);

  return $content;
}

/**
 * Sort callback.
 */
function length_sort_compare($a, $b) {
  $len_a = strlen($a);
  $len_b = strlen($b);
  return $len_a > $len_b ? -1 : ($len_a < $len_b ? 1 : 0);
}


/**
 * Logs error into a file.
 */
function oarc_log_error($text) {
  global $log_file;
  file_put_contents($log_file, '[' . date('Y/m/d@G:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * Establishes database connection.
 * @return
 *   Returns FALSE if db connection fails.
 */
function oarc_ensure_db() {
  global $db_hostname, $db_username, $db_password, $db_name, $db_resource;

  if (!empty($db_resource)) {
    // Connection already established.
    return TRUE;
  }

  $db_resource = mysql_connect($db_hostname, $db_username, $db_password);

  if ($db_resource) {
    if (!mysql_select_db($db_name, $db_resource)) {
      oarc_log_error("Unable to select database: " . mysql_error());
      return FALSE;
    }

  }
  else {
    oarc_log_error("Unable to open database: " . mysql_error());
    return FALSE;
  }

  return TRUE;
}

/**
 * Logs a ReadClearly run.
 */
function oarc_log_run() {
  if (!oarc_ensure_db()) {
    return;
  }

  if (isset($_SERVER['HTTP_REFERER'])) {
    $referer = strtolower($_SERVER['HTTP_REFERER']);
  }
  else {
    $referer = 'unknown';
  }

  $query = "INSERT INTO oarc_log
              (year, month, referer, count)
              VALUES (
                " . date('Y') . ",
                " . date('n') . ",
                '" . mysql_real_escape_string($referer) . "',
                1
              )
              ON DUPLICATE KEY UPDATE count = count + 1;";

  $result = mysql_query($query);
  if (!$result) {
    oarc_log_error("Database error: " . mysql_error());
  }
}

/**
 * Logs processed words into the database.
 *
 * @param $matched_words
 *   Array of words to log. Keys are the words, values are match counts.
 * @param $table
 *   Db table to log to.
 */
function oarc_log_words($matched_words, $table, $glossary = FALSE) {

  if (!oarc_ensure_db()) {
    return;
  }

  if (!in_array($table, array('oarc_unmatched', 'oarc_matched', 'oarc_viewed', 'oarc_translated'))) {
    return;
  }

  if (!$matched_words) {
    return;
  }

  $sanitized_words = array_keys($matched_words);
  foreach ($sanitized_words as $key => $value) {
    $sanitized_words[$key] = '"' . mysql_real_escape_string(strtolower($value)) . '"';
  }

  $month = date('n');
  $year = date('Y');

  if (!$glossary) {
    $glossary = 'default';
  }

  // Retrieve current counts.

  $query = "SELECT * FROM $table WHERE word IN (" . implode(',', $sanitized_words) . ") AND
            year = $year AND month = $month AND glossary = '" . mysql_real_escape_string(strtolower($glossary)) . "'";

  $result = mysql_query($query);
  if (!$result) {
    oarc_log_error('Mysql error: ' . mysql_error());
    return;
  }

  $current_counts = array();
  while ($row = mysql_fetch_assoc($result)) {
    $current_counts[$row['word']] = $row['count'];
  }

  // Merge new numbers.

  $values = array();
  foreach ($matched_words as $word => $count) {
    // Word in the database should be all lowercase.
    $lower_word = strtolower($word);

    if (!empty($current_counts[$lower_word])) {
      $matched_words[$word] += $current_counts[$lower_word];
    }

    $values[] = "($year, $month, '" . mysql_real_escape_string(strtolower($glossary)) . "', '" . mysql_real_escape_string($lower_word) . "', " . $matched_words[$word] . ")";
  }


  $query = "INSERT INTO $table (year, month, glossary, word, count) VALUES " . implode($values, ',') ."
            ON DUPLICATE KEY UPDATE count = VALUES(count)";

  $result = mysql_query($query);
  if (!$result) {
    oarc_log_error('Mysql error: ' . mysql_error());
    return;
  }

}

/**
 * Logs a vote.
 */
function oarc_log_vote($word, $value, $glossary = FALSE) {
  if (!oarc_ensure_db()) {
    return;
  }

  $yes = 0;
  $no = 0;

  switch ($value) {
    case 'yes':
      $yes = 1;
      break;

    case 'no':
      $no = 1;
      break;
  }

  $month = date('n');
  $year = date('Y');

  if (!$glossary) {
    $glossary = 'default';
  }

  $query = "INSERT INTO oarc_votes (year, month, glossary, word, yes, no) VALUES ($year, $month, '" . mysql_real_escape_string(strtolower($glossary)) . "', '" . mysql_real_escape_string(strtolower($word)) . "', $yes, $no)
            ON DUPLICATE KEY UPDATE yes = yes + $yes, no = no + $no ";

  $result = mysql_query($query);
  if (!$result) {
    oarc_log_error('Mysql error: ' . mysql_error());
    return;
  }
}
