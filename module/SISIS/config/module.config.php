<?php

namespace SISIS\Module\Config;

return array (
  'controllers' =>
  array (
    'factories' =>
    array (
      'SISIS\\Controller\\ContentController' => 'VuFind\\Controller\\AbstractBaseFactory',
    ),
    'aliases' =>
    array (
      'Content' => 'SISIS\\Controller\\ContentController',
      'content' => 'SISIS\\Controller\\ContentController',
      'VuFind\\Controller\\ContentController' => 'SISIS\\Controller\\ContentController',
    ),
  ),
  'vufind' =>
  array (
    'plugin_managers' =>
    array (
      'recorddriver' =>
      array (
        'factories' =>
        array (
          'SISIS\\RecordDriver\\SolrMarc' => 'SISIS\\RecordDriver\\SolrDefaultFactory',
          'SISIS\\RecordDriver\\Primo' => 'SISIS\\RecordDriver\\NameBasedConfigFactory',
        ),
        'aliases' =>
        array (
          'VuFind\\RecordDriver\\SolrMarc' => 'SISIS\\RecordDriver\\SolrMarc',
          'VuFind\\RecordDriver\\Primo' => 'SISIS\\RecordDriver\\Primo',
        ),
        'delegators' =>
        array (
          'SISIS\\RecordDriver\\SolrMarc' =>
          array (
            0 => 'SISIS\\RecordDriver\\IlsAwareDelegatorFactory',
          ),
        ),
      ),
      'ils_driver' =>
      array (
        'factories' =>
        array (
          'SISIS\\ILS\\Driver\\SISISNCIP' => 'SISIS\\ILS\\Driver\\SISISNCIPFactory',
        ),
        'aliases' =>
        array (
          'sisisncip' => 'SISIS\\ILS\\Driver\\SISISNCIP',
        ),
      ),
    ),
  ),
);
