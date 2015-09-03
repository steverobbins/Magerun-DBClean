<?php

namespace Dbclean\Magento\Command\Database\Maintain;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanCommand extends AbstractMagentoCommand
{
    /**
     * Forcfulling run?  Will not prompt for confirmation
     *
     * @var boolean
     */
    protected $force;

    /**
     * Write connection to DB
     *
     * @var \Varien_Db_Adapter_Interface
     */
    protected $dbWrite;

    /**
     * Read connection to DB
     *
     * @var \Varien_Db_Adapter_Interface
     */
    protected $dbRead;

    /**
     * Dialog helper
     *
     * @var \Symfony\Component\Console\Helper\DialogHelper
     */
    protected $dialog;

    /**
     * Resource singleton
     *
     * @var \Mage_Core_Model_Resource
     */
    protected $coreResource;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * List of tables in DB
     *
     * @var string[]
     */
    protected $tables;

    /**
     * Groups of resources to truncate
     *
     * @var array
     */
    protected $groups = array(
        'Cache' => array(
            'core/cache',
            'core/cache_tag',
        ),
        'Session' => array(
            'core/session',
        ),
        'Dataflow' => array(
            'dataflow/batch_export',
            'dataflow/batch_import',
        ),
        'Enterprise Admin Logs' => array(
            'enterprise_logging/event',
            'enterprise_logging/event_changes',
        ),
        'Index' => array(
            'index/event',
            'index/process_event',
        ),
        'Logs' => array(
            'log/customer',
            'log/quote_table',
            'log/summary_table',
            'log/summary_type_table',
            'log/url_table',
            'log/url_info_table',
            'log/visitor',
            'log/visitor_info',
            'log/visitor_online',
        ),
        'Reports' => array(
            'reports/event',
            'reports/viewed_product_index',
            'reports/viewed_aggregated_daily',
            'reports/viewed_aggregated_monthly',
            'reports/viewed_aggregated_yearly',
        ),
        'Quotes' => array(
            'sales/quote',
            'sales/quote_address',
            'sales/quote_address_item',
            'sales/quote_item',
            'sales/quote_item_option',
            'sales/quote_payment',
            'sales/quote_shipping_rate',
        ),
    );

    /**
     * Setup command
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('db:maintain:clean-tables')
            ->setDescription('Aggressively cleans out old database records')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Do not prompt for confirmation before truncating tables'
            );;
    }

    /**
     * Run the command
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->detectMagento($this->output, true);
        if (!$this->initMagento()) {
            return;
        }
        $this->force  = $input->getOption('force');
        $this->dialog = $this->getHelper('dialog');
        if (!$this->preExecuteWarning()) {
            return;
        }
        $this->coreResource = \Mage::getSingleton('core/resource');
        $this->dbRead       = \Mage::getSingleton('core/resource')->getConnection('core_read');
        $this->dbWrite      = \Mage::getSingleton('core/resource')->getConnection('core_write');
        $this->tables       = $this->getTables();
        $this->dbWrite->query('SET foreign_key_checks = 0');
        $this->truncateResourceGroups();
        $this->cleanMatch('/_cl$/', 'Changelog', 'truncate');
        $this->cleanMatch('/_category_flat_/', 'Category Flat', 'drop');
        $this->cleanMatch('/_product_flat_/', 'Product Flat', 'drop');
        $this->dbWrite->query('SET foreign_key_checks = 1');
    }

    /**
     * Prompt the user that they're about to do something destructive
     *
     * @return boolean
     */
    protected function preExecuteWarning()
    {
        if (!$this->force) {
            return $this->dialog->askConfirmation(
                $this->output,
                '<question>You\'re about to f@!* with your database.  Are you sure you want to continue?</question> ',
                false
            );
        }
        return true;
    }

    /**
     * Get tables in DB
     *
     * @return string[]
     */
    protected function getTables()
    {
        $stmt = $this->dbRead->query('SHOW TABLES');
        $stmt->execute();
        $tables = array();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Loop through resource groups and truncate tables
     *
     * @return void
     */
    protected function truncateResourceGroups()
    {        
        foreach ($this->groups as $name => $resources) {
            $this->prepareResources($resources);
            if (!count($resources)) {
                continue;
            }
            array_walk($resources, array($this, 'prepareArrayForTableOutput'));
            if (!$this->force) {
                $this->output->writeln(sprintf('<info>%s</info> table(s):', $name));
                $table = new Table($this->output);
                $table
                    ->setRows($resources)
                    ->render();
                $confirm = $this->dialog->askConfirmation(
                    $this->output,
                    '<question>Truncate table(s)?</question> ',
                    false
                );
                if (!$confirm) {
                    continue;
                }
            }
            foreach ($resources as $resourceName) {
                if (!$resourceName[0]) {
                    continue;
                }
                $this->dbWrite->truncateTable($resourceName[0]);
                $this->output->writeln(sprintf('Truncate <info>%s</info>', $resourceName[0]));
            }
        }
    }

    /**
     * Extract table names and prepare for cli rendering
     *
     * @param array $resources
     *
     * @return void
     */
    protected function prepareResources(&$resources)
    {
        foreach ($resources as $key => &$resource) {
            try {
                $table = $this->coreResource->getTableName($resource);   
            } catch (\Mage_Core_Exception $e) {
                // table not exists
                $table = false;
            }
            if ($table === false) {
                unset($resources[$key]);
            } else {
                $resource = $table;
            }
        }
    }

    /**
     * Walk through array and set row columns
     *
     * @param  string $value
     *
     * @return void
     */
    protected function prepareArrayForTableOutput(&$value)
    {
        $value = array($value);
    }

    /**
     * Find and truncate/drop matched tables
     *
     * @param string $pattern
     * @param string $name
     * @param string $action
     *
     * @return void
     */
    protected function cleanMatch($pattern, $name, $action)
    {
        $tables = preg_grep($pattern, $this->tables);
        if (!count($tables)) {
            return;
        }
        array_walk($tables, array($this, 'prepareArrayForTableOutput'));
        if (!$this->force) {
            $this->output->writeln(sprintf('<info>%s</info> table(s):', $name));
            $table = new Table($this->output);
            $table
                ->setRows($tables)
                ->render();
            $confirm = $this->dialog->askConfirmation(
                $this->output,
                sprintf('<question>%s table(s)?</question> ', ucfirst($action)),
                false
            );
            if (!$confirm) {
                return;
            }
        }
        foreach ($tables as $table) {
            $this->dbWrite->{$action . 'Table'}($table[0]);
            $this->output->writeln(sprintf('%s <info>%s</info>', ucfirst($action), $table[0]));
        }
    }
}