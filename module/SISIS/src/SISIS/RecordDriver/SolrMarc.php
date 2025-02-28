<?php

namespace SISIS\RecordDriver;

class SolrMarc extends \VuFind\RecordDriver\SolrMarc
{
  use Feature\CustomMarcAdvancedTrait;

      /**
       * Return the library katkey of this record within the index;
       * useful for retrieving additional information from NCIP2SLNP SISIS module.
       *
       * @param string $library - name of the solr field for katkeys of $library
       *
       * @return string katkey of the item
       */
      public function getKatKey($library)
      {
        if (!isset($this->fields[$library])) {
            return "none";
        }
        return $this->fields[$library];
      }


}
