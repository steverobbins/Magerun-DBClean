<?php

namespace Dbclean\Magento\Command\Database\Maintain;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanCommand extends AbstractMagentoCommand
{
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

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output, true);
        if (!$this->initMagento()) {
            return;
        }
        $force        = $input->getOption('force');
        $dbWrite      = \Mage::getSingleton('core/resource')->getConnection('core_write');
        $dialog       = $this->getHelper('dialog');
        $coreResource = \Mage::getSingleton('core/resource');
        if (!$force) {
            $confirm = $dialog->askConfirmation(
                $output,
                '<question>You\'re about to f@!* with your database.  Are you sure you want to continue?</question> ',
                false
            );
            if (!$confirm) {
                return;
            }
        }
        $dbWrite->query('SET foreign_key_checks = 0');
        foreach ($this->groups as $name => $resources) {
            $this->prepareResources($resources);
            if (!count($resources)) {
                continue;
            }
            if (!$force) {
                $output->writeln(sprintf('<info>%s</info> table(s):', $name));
                $table = new Table($output);
                $table
                    ->setRows($resources)
                    ->render();
                $confirm = $dialog->askConfirmation(
                    $output,
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
                $dbWrite->truncateTable($resourceName[0]);
                $output->writeln(sprintf('Truncated <info>%s</info>', $resourceName[0]));
            }
        }
        $dbWrite->query('SET foreign_key_checks = 1');
    }

    protected function prepareResources(&$resources)
    {
        foreach ($resources as $key => &$resource) {
            try {
                $table = \Mage::getSingleton('core/resource')->getTableName($resource);   
            } catch (\Mage_Core_Exception $e) {
                // table not exists
                $table = false;
            }
            if ($table === false) {
                unset($resources[$key]);
            } else {
                $resource = array($table);
            }
        }
    }
}