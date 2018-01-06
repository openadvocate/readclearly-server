<?php

require "conf.php";
require "functions.php";

// Get posted content.
$post = file_get_contents('php://input');
if (empty($post)) {
  print 'Incomplete request.';
  exit;
}

$input = json_decode($post, TRUE);

$default_glossary = !empty($input['glossary']) ? $input['glossary'] : FALSE;
$glossary_data = load_glossary($default_glossary);
if (empty($glossary_data)) {
  print 'Unable to load glossary.';
  exit;
}

// Process hover notifications.
if (!empty($input['hovered'])) {
  oarc_log_words(array($input['hovered'] => 1), 'oarc_viewed', $glossary_data['file']);
  exit;
}

// Process translation notifications.
if (!empty($input['translated'])) {
  oarc_log_words(array($input['translated'] => 1), 'oarc_translated', $glossary_data['file']);
  exit;
}

// Process voting notifications.
if (!empty($input['voted']) && !empty($input['value'])) {
  oarc_log_vote($input['voted'], $input['value'], $glossary_data['file']);
  exit;
}

// If the request has none of the above parameters, it must contain 'content'.
if (empty($input['content'])) {
  print 'Incomplete request.';
  exit;
}

$content = process_input($input['content'], $glossary_data);

// Print output.
$content .= '<!-- ReadClearly glossary: ' . $glossary_data['file'] . ' -->';
print ($content);
