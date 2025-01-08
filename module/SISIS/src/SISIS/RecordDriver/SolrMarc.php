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

        /**
       * Get the full title of the record.
       *
       * @return string
       */
       public function getTitle()
       {
           //check if title has a subtitle
           if (isset($this->fields['title_sub']))
           {
             //display title: subtitle
             $title = $this->getShortTitle() . ((!empty($this->fields['title_sub']))?' : ':' ') . $this->getSubtitle() . $this->getTitleSection();
             return $title;
           } elseif (strlen($this->getTitleSection())>1) {
             $title = $this->getShortTitle() . ' ' . $this->getTitleSection();
             return $title;
           } elseif(isset($this->fields['title_short'])) {
             $title = $this->getShortTitle();
             return $title;
           } else {
             return 'Kein Titel';
           }
       }

        /**
       * Get a highlighted title string, if available.
       *
       * @return string
       */
      public function getHighlightedTitle()
      {
          // Don't check for highlighted values if highlighting is disabled:
          if (!$this->highlight) {
              return '';
          }

          //check if title has a subtitle
          if ((isset($this->fields['title_sub'])))
          {
            //display title: subtitle
            return $this->highlightDetails['title_short'][0] . ((!empty($this->fields['title_sub']))?' : ':' ') . $this->highlightDetails['title_sub'][0] . $this->highlightDetails[$this->getTitleSection()];
          } elseif (strlen($this->getTitleSection())>1) {
            //display title / titlesection
            return $this->highlightDetails['title_short'][0] . ' ' . $this->highlightDetails[$this->getTitleSection()];
          } else
          {
            return $this->highlightDetails['title'][0] ?? '';
          }
      }


          /**
           *
           * Return the journal information available in UBLMU;
           *
           * @return array journal information.
           */

          public function getJournalInfo()
          {
            $primaryFields = [
              '981' => ['a']];
            $journalinfo = $this->getJournalInfoFromMARC($primaryFields);
            return (array)$journalinfo;
          }


          /**
          *
          * Return the information needed to perform a CLD request;
          *
          * @return array cld information.
          */
          public function getCLDInfo()
          {
            $cldinfo = $this->getCLDInfoFromMARC();
            return (array)$cldinfo;
          }

          /**
          *
          * Return true if there are volume, false if not
          *
          */
          public function linkJournalLocation($location) {
            $url = 'http://www.ub.uni-muenchen.de/bibliotheken/bibs-a-bis-z/' . $location;
            $location_url = $this->ils->getLocationURL($url);
            return (string)$location_url;
          }


          /**
          *
          * check if isPartOf_id is filled and return katkey
          *
          */
          public function getIsPartOf() {
            if (isset($this->fields['isPartOf'])) {
              $isPartOf = $this->fields['isPartOf'];
              foreach ($isPartOf as $part) {
                if(str_starts_with($part, '(DE-604)')) {

                } else {
                  return $part;
                }
              }
            }
          }


          /**
          * Support method for getBVNumber() -- look for bvnumber information in the MARC record.
          *
          * @return string
          */
          protected function getBVNumber()
          {
           if (isset($this->fields['bvnumber']))
           {
             //display title: subtitle
             $bvnumber = $this->fields['bvnumber'];
             return $bvnumber;
           }
          }


          /**
           *
           * Return true if record is an UG or false if not
           *
           * @return bool UG
           */

          public function isUG()
          {
            $isUG = $this->isUGFromMarc();
            return (bool)$isUG;
          }

          /**
           *
           * Return all shelfmarks for the record
           *
           * @return array shelfmark
           */
          public function getShelfmarks()
        	{
        	   return (array)($this->fields['shelfmark'] ?? []);
        	}

          /**
           *
           * Return RVK Notation for the record
           *
           * @return array rvk notation
           */
          public function getRVKNotation()
          {
            if (isset($this->fields['classification_rvk']))
            {
              $rvk = $this->fields['classification_rvk'];
              return $rvk;
            }
          }


}
