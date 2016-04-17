<?php
namespace Terminus\Commands;

use Terminus\Loggers\Debugger;
use Terminus\Models\Collections\Sites;
use Terminus\Utils;

/**
 * Sync's a Site Locally
 *
 * @command site
 */
class PipeCommand extends CommandWithSSH {
  /**
   * Object constructor
   *
   * @param array $options
   */
  public function __construct(array $options = []) {
    $options['require_login'] = true;
    parent::__construct($options);
    $this->sites = new Sites();
  }

  /**
   * Syncs a Pantheon Site to a Drush Alias
   *
   * <commands>...
   * : The Drush Alias to the Site you want to sync to
   *
   * [--site=<site>]
   * : Site on which to sync from
   *
   * [--env=<env>]
   * : Environment on which to sync from
   *
   * [--progress]
   * : Shows Progress. Requires pv to be installed on the target machine.
   *
   * @subcommand pipe
   * @alias sync
   */
  public function pipe($args, $assoc_args) {
    $start_bootstrapping = microtime(TRUE);

    $site = $this->sites->get(
      $this->input()->siteName(array('args' => $assoc_args))
    );

    if (empty($args)) {
      $this->failure('Drush Alias is a required Argument.');
    }

    $drush_alias = array_shift($args);

    ob_start();

    passthru("drush sa $drush_alias", $error_code);

    if ($error_code == 0) {
      // Read the Alias
      eval(ob_get_clean());
    }

    if (empty($aliases)) {
      $this->failure('Drush Alias is Invalid');
    }

    $env_id   = $this->input()->env(array('args' => $assoc_args, 'site' => $site));

    /* @var $environment \Terminus\Models\Environment */
    $environment = $site->environments->get($env_id);

    // Wake the Site
    $environment->wake();

    $alias = reset($aliases);
//    $remote_host = FALSE;
//
//    if (isset($alias['remote-host'])) {
//      $remote_host = TRUE;
//    }

    $pv = !empty($assoc_args['progress']);

    $commands = [];

    $drush_alias_source = $this->escapeShellArg($this->getInlineDrushAlias($environment));
    $commands[] = "drush $drush_alias sql-drop -y 1>/dev/null";

    $dump_commands[] = $this->exportDBCommand($environment);
    $dump_commands[] = 'gzip';

    $dump_cmd = implode(' | ', $dump_commands);

    $dump_php_cmd = $this->escapeShellArg('passthru(' . $this->escapeShellArg($dump_cmd) . ')');
    $commands[] = "drush $drush_alias_source ev $dump_php_cmd";

    if ($pv) {
      $commands[] = 'pv -cfN importing';
    }

    $commands[] = 'gunzip';
    $commands[] = $this->importDBDrushCommand($drush_alias);

    $cmd = implode(' | ', $commands);

    // Wrap the SSH Command in Drush SSH if need be.
//    if ($remote_host) {
//      $cmd = $this->escapeShellArg($cmd);
//      $cmd = "drush $drush_alias --tty ssh $cmd 2>&1";
//    }

    $this->log()->debug('Bootstraping Time: ' . round((microtime(TRUE) - $start_bootstrapping)* 1000, 2));
    $this->log()->debug("Running Command: $cmd");

    $this->log()->info('Importing Database...');

    $start_piping = microtime(TRUE);

    passthru($cmd, $error_code);

    $this->log()->debug('Piping Time: ' . round((microtime(TRUE) - $start_piping)* 1000, 2));
  }

  /**
   * Gets the Import DB Command for a Drush Alias
   * @param string $drush_alias
   * @return string
   */
  protected function importDBDrushCommand($drush_alias) {
    $command = "drush $drush_alias sql-cli --extra=--compress";
    return $command;
  }

  /**
   * Gets the DB Export Command for a Site
   * @param \Terminus\Models\Environment $environment
   * @return string
   */
  protected function exportDBCommand(\Terminus\Models\Environment $environment) {
    $export_command = 'mysqldump';

    // Nysqldump is stupid...
    $export_parameters = str_replace('--database=', '' , $this->getCommandLineDatabaseOptions($environment));
    $export_parameters .= ' --compress --disable-keys --quick --quote-names';
    $export_parameters .= ' --add-drop-table --add-locks --create-options --no-autocommit --single-transaction';
    $export_parameters .= ' --skip-extended-insert --complete-insert --order-by-primary';
    $export_command .= ' ' . $export_parameters;

    //@TODO: Add Structure Tables
    //$exec = "( mysqldump --no-data $extra " . implode(' ', $structure_tables) . " && $exec; ) ";
    
    return $export_command;
  }

  /**
   * Get Command Line Database Options
   */
  protected function getCommandLineDatabaseOptions(\Terminus\Models\Environment $environment) {
    $connection_info = $environment->connectionInfo();

    $parameters = [
      '--database=' . $connection_info['mysql_database'],
      '--user=' . $connection_info['mysql_username'],
      '--password=' . $connection_info['mysql_password'],
      '--host=' . $connection_info['mysql_host'],
      '--port=' . $connection_info['mysql_port'],
    ];

    array_map([$this, 'escapeShellArg'], $parameters);

    return implode(' ', $parameters);
  }

  /**
   * @param $environment
   * @return mixed
   */
  protected function getInlineDrushAlias(\Terminus\Models\Environment $environment) {
    $connection_info = $environment->connectionInfo();

    $drush_url = $connection_info['sftp_username'] . '@' . $connection_info['sftp_host'] . '/';

    $drush_url .= '?' . http_build_query([
      'ssh-options' => '-p 2222 -o "AddressFamily inet"',
      'db-url' => $connection_info['mysql_url'],
    ]);

    return $drush_url;
  }

  /**
   * Platform Independent - Escape Shell Arg. Taken from Drush.
   */
  protected function escapeShellArg($arg, $raw = FALSE) {
    // Short-circuit escaping for simple params (keep stuff readable)
    if (preg_match('|^[a-zA-Z0-9.:/_-]*$|', $arg)) {
      return $arg;
    }
    elseif (Utils\isWindows()) {
      // Double up existing backslashes
      $arg = preg_replace('/\\\/', '\\\\\\\\', $arg);

      // Double up double quotes
      $arg = preg_replace('/"/', '""', $arg);

      // Double up percents.
      $arg = preg_replace('/%/', '%%', $arg);

      // Only wrap with quotes when needed.
      if(!$raw) {
        // Add surrounding quotes.
        $arg = '"' . $arg . '"';
      }

      return $arg;
    }
    else {
      // For single quotes existing in the string, we will "exit"
      // single-quote mode, add a \' and then "re-enter"
      // single-quote mode.  The result of this is that
      // 'quote' becomes '\''quote'\''
      $arg = preg_replace('/\'/', '\'\\\'\'', $arg);

      // Replace "\t", "\n", "\r", "\0", "\x0B" with a whitespace.
      // Note that this replacement makes Drush's escapeshellarg work differently
      // than the built-in escapeshellarg in PHP on Linux, as these characters
      // usually are NOT replaced. However, this was done deliberately to be more
      // conservative when running _drush_escapeshellarg_linux on Windows
      // (this can happen when generating a command to run on a remote Linux server.)
      $arg = str_replace(array("\t", "\n", "\r", "\0", "\x0B"), ' ', $arg);

      // Only wrap with quotes when needed.
      if(!$raw) {
        // Add surrounding quotes.
        $arg = "'" . $arg . "'";
      }

      return $arg;
    }
  }
}
