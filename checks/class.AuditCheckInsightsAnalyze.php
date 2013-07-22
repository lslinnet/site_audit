<?php
/**
 * @file
 * Contains \AuditCheckInsightsAnalyze.
 */

class AuditCheckInsightsAnalyze extends AuditCheck {
  /**
   * Implements \AuditCheck\getLabel().
   */
  public function getLabel() {
    return dt('Report');
  }

  /**
   * Implements \AuditCheck\getDescription().
   */
  public function getDescription() {
    return dt('Full report at https://developers.google.com/speed/pagespeed/insights');
  }

  /**
   * Implements \AuditCheck\getResultFail().
   */
  public function getResultFail() {
    if (!empty($this->registry['errors'])) {
      if ($this->html) {
        $ret_val = '<ul><li>' . implode('</li><li>', $this->registry['errors']) . '</li></ul>';
      }
      else {
        $ret_val = implode(PHP_EOL, $this->registry['errors']);
      }
      return $ret_val;
    }
    $this->getResultPass();
  }

  /**
   * Implements \AuditCheck\getResultInfo().
   */
  public function getResultInfo() {
    return dt('Views is not enabled.');
  }

  /**
   * Implements \AuditCheck\getResultPass().
   */
  public function getResultPass() {
    if ($this->abort) {
      return;
    }
    $ret_val = dt('@title - @id: @score', array(
      '@title' => $this->registry['json_result']->title,
      '@id' => $this->registry['json_result']->id,
      '@score' => $this->registry['json_result']->score,
    ));

    if (drush_get_context('DRUSH_VERBOSE')) {
      // Page Stats.
      $stats = array();
      foreach ($this->registry['json_result']->pageStats as $stat_name => $count) {
        $formatted_stat_name = ucfirst(preg_replace('/(?<!^)((?<![[:upper:]])[[:upper:]]|[[:upper:]](?![[:upper:]]))/', ' $1', $stat_name));
        if (stripos($stat_name, 'bytes') !== FALSE) {
          $stats[] = dt('@stat_name: @kbKB', array(
            '@stat_name' => $formatted_stat_name,
            '@kb' => round($count / 1024, 2),
          ));
        }
        else {
          $stats[] = dt('@stat_name: @count', array(
            '@stat_name' => $formatted_stat_name,
            '@count' => $count,
          ));
        }
      }
      if ($this->html) {
        $ret_val .= '<dl><dt>' . dt('Page stats') . '</dt>';
        $ret_val .= '<dd>' . implode('</dd><dd>', $stats) . '</dd></dl>';
      }
      else {
        foreach ($stats as &$stat) {
          $stat = '      - ' . $stat;
        }
        $ret_val .= PHP_EOL . '    ' . dt('Page stats') . PHP_EOL;
        $ret_val .= implode(PHP_EOL, $stats);
      }

      $impact_filter = drush_get_option('impact');

      // Results.
      if ($this->html) {
        $ret_val .= '<h3>' . dt('Detailed results') . '</h3>';
      }
      else {
        $ret_val .= PHP_EOL . '    ' . dt('Detailed results:') . PHP_EOL;
      }
      $rendered_result_count = 0;
      foreach ($this->registry['json_result']->formattedResults->ruleResults as $resultValues) {
        // Filter out based on impact threshold.
        if ($resultValues->ruleImpact < $impact_filter) {
          continue;
        }
        $rendered_result_count++;

        // Build impact label.
        $impact = '';
        if ($resultValues->ruleImpact >= 3) {
          $impact = dt('(HIGH impact: @ruleImpact)', array(
            '@ruleImpact' => $resultValues->ruleImpact,
          ));
        }
        elseif ($resultValues->ruleImpact > 0) {
          $impact = dt('(low impact: @ruleImpact)', array(
            '@ruleImpact' => $resultValues->ruleImpact,
          ));
        }

        // Render Rule, score and impact.
        $ret_val .= '      ' . dt('@localizedRuleName: @ruleScore @impact', array(
          '@localizedRuleName' => $resultValues->localizedRuleName,
          '@ruleScore' => $resultValues->ruleScore,
          '@impact' => $impact,
        )) . PHP_EOL;

        if (isset($resultValues->urlBlocks)) {
          foreach ($resultValues->urlBlocks as $block) {
            // Header.
            if (!isset($block->header->args)) {
              $header = google_json_text_replacement($block->header->format);
            }
            else {
              $header = google_json_text_replacement($block->header->format, $block->header->args);
            }
            if ($this->html) {
              $ret_val .= '<h4>' . $header . '</h4>';
            }
            else {
              $ret_val .= '        ' . $header . PHP_EOL;
            }
            if (isset($block->urls) && !empty($block->urls)) {
              $urls = array();
              $limit = drush_get_option('limit', 0);
              $count = 0;
              foreach ($block->urls as $url) {
                if ($limit > 0) {
                  if (++$count > $limit) {
                    continue;
                  }
                }
                $urls[] = google_json_text_replacement($url->result->format, $url->result->args);
              }
              if ($limit > 0 && ($limit != count($block->urls)) && ($limit < count($block->urls))) {
                $urls[] = dt('(Showing @limit out of @total total)', array(
                  '@limit' => $limit,
                  '@total' => count($block->urls),
                ) . ($this->html ? '' : PHP_EOL));
              }
              if ($this->html) {
                $ret_val .= '<ul><li>' . implode('</li><li>', $urls) . '</li></ul>';
              }
              else {
                $ret_val .= '          ' . implode(PHP_EOL . '          ', $urls);
                $ret_val .= PHP_EOL;
              }
            }
          }
        }
      }

      // Explain if there are no results so it doesn't look like its broken.
      if ($rendered_result_count == 0) {
        if ($impact_filter) {
          $ret_val .= dt('Nice, no problems to report!');
        }
        else {
          $ret_val .= dt('No results, which is unusual...');
        }
      }
    }
    return $ret_val;
  }

  /**
   * Implements \AuditCheck\getResultWarning().
   */
  public function getResultWarning() {
    return $this->getResultPass();
  }

  /**
   * Implements \AuditCheck\getAction().
   */
  public function getAction() {}

  /**
   * Implements \AuditCheck\calculateScore().
   */
  public function calculateScore() {
    $pso_url = 'https://www.googleapis.com/pagespeedonline/v1/runPagespeed';
    $pso_url .= '?url=' . $this->registry['url'];
    $pso_url .= '&key=' . $this->registry['key'];

    $ch = curl_init($pso_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($ch);

    $this->registry['json_result'] = json_decode($result);

    // Failure.
    if (isset($this->registry['json_result']->error)) {
      $this->abort = TRUE;
      $this->registry['errors'] = array();
      foreach ($this->registry['json_result']->error->errors as $error) {
        $this->registry['errors'][] = dt('@message (@domain - @reason)', array(
          '@message' => $error->message,
          '@domain' => $error->domain,
          '@reason' => $error->reason,
        ));
      }
      return AuditCheck::AUDIT_CHECK_SCORE_FAIL;
    }

    // Overview.
    if ($this->registry['json_result']->score > 80) {
      return AuditCheck::AUDIT_CHECK_SCORE_PASS;
    }
    elseif ($this->registry['json_result']->score > 60) {
      return AuditCheck::AUDIT_CHECK_SCORE_WARN;
    }
    return AuditCheck::AUDIT_CHECK_SCORE_ERROR;
  }
}

/**
 * Perform brute force variable replacement on a Google formatted string.
 *
 * @param string $format
 *   Content to be formatted.
 * @param array $args
 *   Optional; contains standard objects.
 * @return string
 *   Human readable formatted content.
 */
function google_json_text_replacement($format, $args = array()) {
  if (!$args) {
    return $format;
  }
  // If there's a better way of doing this, please let me know.
  $format_sprintf = preg_replace('/\$\d/', '%s', $format);
  $values = array();
  foreach ($args as $arg) {
    $values[] = $arg->value;
  }
  return vsprintf($format_sprintf, $values);
}