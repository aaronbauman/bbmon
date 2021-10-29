<?php

namespace Bbmon\Cli;

use Consolidation\OutputFormatters\Formatters\TableFormatter;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use GuzzleHttp\Client;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Robo;
use Robo\Tasks;

class BbmonCommands extends Tasks
{
  use ContainerAwareTrait;
  use LoggerAwareTrait;

  protected $httpClient;

  /**
     * Show bitbucket repos.
     *
     * @command repos
     */
    public function repos()
    {
      $this->output()->writeln(print_r($this->getRepos(), 1));
    }

  /**
   * Show bitbucket pipe status.
   *
   * @command pipes
   */
  public function pipes()
  {
    $this->repos = $this->getRepos();
    foreach ($this->repos as $repo) {
      $pipes = $this->getPipes($repo);
      $this->processPipes($pipes, $repo);
    }
  }


  protected function getPipes(\stdClass $repo) {
    $options = static::defaultRequestOptions();
    $options['query']['sort'] = '-created_on';
    $options['query']['pagelen'] = 100;
    return $this->bbRequest('repositories/messageagency/' . $repo->slug . '/pipelines/', 'GET', $options);
  }

  protected function processPipes(array $pipes, \stdClass $repo) {
    if (empty($pipes)) {
      return;
    }
    $jobs = $this->getPipeJobs($pipes);
    foreach ($jobs as $target => $job) {
      $report[] = [
        'target' => $target,
        'success' => $job['success'] . '/' . $job['count'] . ' (' . round(100 * ($job['success'] / $job['count']), 1) . ')',
        'time' => round($job['time'] / $job['count'], 1) . ' seconds',
        'last_fail' => $job['last_fail'],
        'last_success' => $job['last_success'],
      ];
    }
    $this->handleResults($report, $repo);
  }

  protected function getPipeJobs($pipes) {
    foreach ($pipes as $pipe) {
      if (empty($jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern])) {
        $jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern] = [
          'success' => $pipe->state->result->name == 'SUCCESSFUL' ? 1 : 0,
          'count' => 1,
          'time' => $pipe->build_seconds_used,
          'last_fail' => $pipe->state->result->name == 'SUCCESSFUL' ? 'N/A' : $pipe->completed_on,
          'last_success' => $pipe->state->result->name == 'SUCCESSFUL' ? $pipe->completed_on : 'N/A'
        ];
      }
      else {
        $jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['count']++;
        if ($pipe->state->result->name == 'SUCCESSFUL') {
          $jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['success']++;
          if ($jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['last_success'] == 'N/A') {
            // Jobs are sorted by created_on, so we can stop at the first instance here..
            $jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['last_success'] = $pipe->completed_on;
          }
        }
        else {
          if ($jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['last_fail'] == 'N/A') {
            // Same here.
            // Only fill in "last fail" if it was not yet set, and we can be sure that's the most recent.
            $jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['last_fail'] = $pipe->completed_on;
          }
        }
        $jobs[$pipe->target->selector->type . '::' . $pipe->target->selector->pattern]['time'] += $pipe->build_seconds_used;
      }
    }
    return $jobs;
  }

  protected function handleResults(array $report, \stdClass $repo) {
    $tableFormatter = new TableFormatter();
    $this->output()->writeln('==========================================');
    $this->output()->writeln('Pipelines report for ' . $repo->name);
    $this->output()->writeln('==========================================');
    $options = new FormatterOptions();
    $report = new RowsOfFields($report);
    $transformer = $report->restructure($options);
    $tableFormatter->write($this->output(), $transformer, $options);
  }

  protected function getRepos() {
    $options = static::defaultRequestOptions();
    $options['query'] = ['pagelen' => 100];
    $options['query']['q'] = 'updated_on>=' . date('Y-m-d', strtotime('-1 year'));
    $repos = $this->bbRequest('repositories/messageagency', 'GET', $options);
    return $repos;
  }

  public static function defaultRequestOptions() {
    return [
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode(getenv('BITBUCKET_USER') . ':' . getenv('BITBUCKET_PASS')),
      ],
    ];
  }

  protected function bbRequest($path, $method = 'GET', $options = []) {
    if (empty($options)) {
      $options = static::defaultRequestOptions();
    }
    $url = 'https://api.bitbucket.org/2.0/' . $path;
    $values = [];
    while (TRUE) {
      $response = $this->httpClient()->request($method, $url, $options);
      $data = json_decode($response->getBody()->getContents());
      //      print_r($data);
      if (!empty($data->values) && is_array($data->values)) {
        $values = array_merge($values, $data->values);
      }
      elseif ($data->size == 0) {
        return [];
      }
      else {
        return $data;
      }
      if (!empty($data->next)) {
        $url = $data->next;
        // Query is included in next-url.
        unset($options['query']);
      }
      else {
        break;
      }
    }
    return $values;
  }

  protected function httpClient() {
    if (empty($this->httpClient)) {
      $this->httpClient = new Client();
    }
    return $this->httpClient;
  }


}
