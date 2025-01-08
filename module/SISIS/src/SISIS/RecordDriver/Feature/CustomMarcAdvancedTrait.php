<?php

namespace SISIS\RecordDriver\Feature;

use VuFind\View\Helper\Root\RecordLinker;
use VuFind\XSLT\Processor as XSLTProcessor;

trait CustomMarcAdvancedTrait
{

  /**
   * Get the text of the part/section portion of the title.
   *
   * @return string
   */
  public function getTitleSection()
  {
      //return $this->getFirstFieldValue('245', ['n', 'p']);
      return ((!empty($this->getFirstFieldValue('245', ['n'])))?'/':'') . $this->getFirstFieldValue('245', ['n']) . ((!empty($this->getFirstFieldValue('245', ['p'])))?': ':' ') . $this->getFirstFieldValue('245', ['p']);
  }

  public function getPublicationPlace()
  {
    return $this->getPublicationInfo('a');
  }

  /**
   * Support method for getSeries() -- given a field specification, look for
   * series information in the MARC record.
   *
   * @param array $fieldInfo Associative array of field => subfield information
   * (used to find series name)
   *
   * @return array
   */
  protected function getSeriesFromMARC($fieldInfo)
  {
      $matches = [];

      // Loop through the field specification....
      foreach ($fieldInfo as $field => $subfields) {
          // Did we find any matching fields?
          $series = $this->getMarcReader()->getFields($field);
          foreach ($series as $currentField) {
              // Can we find a name using the specified subfield list?
              $name = $this->getSubfieldArray($currentField, $subfields);
              if (isset($name[0])) {
                  $currentArray = ['name' => $name[0]];

                  // Can we find a number in subfield v?  (Note that number is
                  // always in subfield v regardless of whether we are dealing
                  // with 440, 490, 800 or 830 -- hence the hard-coded array
                  // rather than another parameter in $fieldInfo).
                  $number = $this->getSubfieldArray($currentField, ['v']);
                  if (isset($number[0])) {
                      $currentArray['number'] = $number[0];
                  }

                  //add id for series field
                  $id = $this->getSubfieldArray($currentField, ['w']);
                  if (isset($id[0])) {
                    $id_str=substr($id[0], 0, strrpos($id[0], ' '));
                      $currentArray['id'] = $id_str;
                  }

                  // Save the current match:
                  $matches[] = $currentArray;
              }
          }
      }

      return $matches;
  }

  /**
 * Return an array of associative URL arrays with one or more of the following
 * keys:
 *
 * <li>
 *   <ul>desc: URL description text to display (optional)</ul>
 *   <ul>url: fully-formed URL (required if 'route' is absent)</ul>
 *   <ul>route: VuFind route to build URL with (required if 'url' is absent)</ul>
 *   <ul>routeParams: Parameters for route (optional)</ul>
 *   <ul>queryString: Query params to append after building route (optional)</ul>
 * </li>
 *
 * @return array
 */
public function getURLs()
{
    $retVal = [];
    $ezbVal = [];
    $dbisVal = [];
    $ownerVal = [];
    $fulltextVal = [];
    $kostenfreiVal = [];
    $otherVal = [];

    // Check 856 fields first regarding fields x EZB and z kostenfrei
    $fieldsToCheck = [
        '856' => ['x', 'z', '3']         //x EZB, z kostenfrei
    ];

    foreach ($fieldsToCheck as $field => $subfields) {
        $urls = $this->getMarcReader()->getFields($field);
        foreach ($urls as $url) {
            // Is there an address in the current field?
            $address = $this->getSubfield($url, 'u');
            if ($address) {
                // Check if x EZB or z kostenfrei
                if ($this->getSubfield($url, 'x') == 'EZB') {
                  $address = $address.'&bibid=UBM';
                  $ezbVal[] = ['url' => $address, 'desc' => 'EZB'];
                  //$retVal[] = ['url' => $address, 'desc' => 'EZB'];
                  break;
		            }
                elseif ($this->getSubfield($url, 'z') == 'kostenfrei') {
			            $kostenfreiVal[] = ['url' => $address, 'desc' => 'kostenfrei'];
                  //$retVal[] = ['url' => $address, 'desc' => 'kostenfrei'];
		            }
                elseif($this->getSubfield($url, '3') == 'Volltext') {
                  $fulltextVal[] = ['url' => $address, 'desc' => $this->getSubfield($url, '3')];
                }
                elseif ($this->getSubfield($url, '3') == ('Inhaltstext' || 'Inhaltsverzeichnis' || 'Abstract')) {
                  $otherVal[] = ['url' => $address, 'desc' => $this->getSubfield($url, '3')];
                }
            }
       }
    }

    //always prefer ezb links
    if(!(empty($ezbVal))) {
      foreach ($ezbVal as $x => $value) {
        $address = $value['url'];
        $desc = $value['desc'];
        $retVal[] = ['url' => $address, 'desc' => $desc];
      }
      //only add kostenfrei links if no ezb link is present
    } elseif (!(empty($kostenfreiVal))) {
      foreach ($kostenfreiVal as $x => $value) {
        $address = $value['url'];
        $desc = $value['desc'];
        $retVal[] = ['url' => $address, 'desc' => $desc];
      }
    }

    // Check 982 fields for UBM01
    $fieldsToCheck = [
        '982' => ['3', 'x', 'z', 'a']         //custom url, l=UBM01
    ];

    foreach ($fieldsToCheck as $field => $subfields) {
        $urls = $this->getMarcReader()->getFields($field);
        foreach ($urls as $url) {
            // Is there an address in the current field?
            $address = $this->getSubfield($url, 'u');
            if ($address) {
                // Is there a description?  If not, just use the URL itself.
                foreach ($subfields as $current) {
                    $desc = $this->getSubfield($url, $current);
                    if ($desc) {
                        break;
                    }
                }
                //always add values in field 982 if ubm01 is declared as owner or if no owner is menti
                $owner = $this->getSubfield($url, 'l');
                if($owner == "UBM01") {
                  $ownerVal[] = ['url' => $address, 'desc' => $desc ?: $address];
                  $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
                } elseif (empty($owner)) {
                  if((str_contains($address,"bib_id=ub_m") && str_contains($address,"dbis"))) {
                    $dbisVal[] = ['url' => $address, 'desc' => 'DBIS'];
                    $retVal[] = ['url' => $address, 'desc' => 'DBIS'];;
                  } elseif(empty($retVal)) {
                    $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
                  } elseif($desc != "Volltext" && $desc != 'URL des Erstveröffentlichers' && $desc != "Verlag" && $desc != "Deutschlandweit zugänglich" && $desc != "EZB" && $desc != "EZB Link" && $desc != "Read me" && $desc != "Readme") {
                    $otherVal[] = ['url' => $address, 'desc' => $desc ?: $address];
                  }
                }
            }
        }
    }

    //add fulltext links from 856 to retVal if no ezb, dbis or owner link is present
    if(empty($ezbVal) && empty($ownerVal) && empty($dbisVal)) {
      foreach ($fulltextVal as $x => $value) {
        $address = $value['url'];
        $desc = $value['desc'];
        $retVal[] = ['url' => $address, 'desc' => $desc];
      }
    }

    //add non-fulltext links to return value
    foreach ($otherVal as $x => $value) {
      $address = $value['url'];
      $desc = $value['desc'];
      $retVal[] = ['url' => $address, 'desc' => $desc];
    }

    return $retVal;
}

/**
* Return an array of ALL associative URL arrays with one or more of the following
* keys:
*
* <li>
*   <ul>desc: URL description text to display (optional)</ul>
*   <ul>url: fully-formed URL (required if 'route' is absent)</ul>
*   <ul>route: VuFind route to build URL with (required if 'url' is absent)</ul>
*   <ul>routeParams: Parameters for route (optional)</ul>
*   <ul>queryString: Query params to append after building route (optional)</ul>
* </li>
*
* @return array
*/
public function getAllFulltextURLs()
{
  $retVal = [];

  // Check 856 fields
  $fieldsToCheck = [
      '856' => ['x', 'z', '3']
  ];

  foreach ($fieldsToCheck as $field => $subfields) {
    $urls = $this->getMarcReader()->getFields($field);
    foreach ($urls as $url) {
      // Is there an address in the current field?
      $address = $this->getSubfield($url, 'u');
      if ($address) {
        foreach ($subfields as $current) {
            $desc = $this->getSubfield($url, $current);
            if ($desc) {
                break;
            }
        }
        // Check for 3 Volltext
        if ((str_contains($this->getSubfield($url, '3'),'Volltext'))) {
          if ($this->getSubfield($url, 'x') == 'EZB') {
            $address = $address.'&bibid=UBM';
            $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
          } elseif ($this->getSubfield($url, 'x') == 'DBIS') {
            if(str_contains($this->getSubfield($url, 'u'),'&bib_id=ub_m')) {
              $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
            }
          } else {
            $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
          }
        }
      }
    }
  }

  // Check 982 fields for UBM01 and links with no owner set (do not show links owned by other bib than UBM01!)
  $fieldsToCheck = [
      '982' => ['3', 'x', 'z', 'a']         //custom url, l=UBM01
  ];

  foreach ($fieldsToCheck as $field => $subfields) {
    $urls = $this->getMarcReader()->getFields($field);
    foreach ($urls as $url) {
      // Is there an address in the current field?
      $address = $this->getSubfield($url, 'u');
      if ($address) {
        foreach ($subfields as $current) {
            $desc = $this->getSubfield($url, $current);
            if ($desc) {
                break;
            }
        }
        //always add values in field 982 if ubm01 is declared as owner or if no owner is menti
        $owner = $this->getSubfield($url, 'l');
        if($owner == "UBM01") {
          $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
        } elseif (empty($owner)) {
          if(((str_contains($this->getSubfield($url, 'x'),'Volltext'))||(str_contains($this->getSubfield($url, 'x'),'EZB'))||(str_contains($this->getSubfield($url, 'u'),'dbis')))) {
            if(str_contains($this->getSubfield($url, 'u'),'dbis')) {
              $retVal[] = ['url' => $address, 'desc' => 'DBIS'];
            } else {
              $retVal[] = ['url' => $address, 'desc' => $desc ?: $address];
            }
          }
        }
      }
    }
  }

  return $retVal;
}

/**
* Return an array of ALL associative URL arrays with one or more of the following
* keys:
*
* <li>
*   <ul>desc: URL description text to display (optional)</ul>
*   <ul>url: fully-formed URL (required if 'route' is absent)</ul>
*   <ul>route: VuFind route to build URL with (required if 'url' is absent)</ul>
*   <ul>routeParams: Parameters for route (optional)</ul>
*   <ul>queryString: Query params to append after building route (optional)</ul>
* </li>
*
* @return array
*/
public function getAllOtherURLs()
{
  $retVal = [];

  // Check 856 fields
  $fieldsToCheck = [
      '856' => ['x', 'z', '3']
  ];

  foreach ($fieldsToCheck as $field => $subfields) {
    $urls = $this->getMarcReader()->getFields($field);
    foreach ($urls as $url) {
      // Is there an address in the current field?
      $address = $this->getSubfield($url, 'u');
      if ($address) {
        foreach ($subfields as $current) {
            $desc = $this->getSubfield($url, $current);
            if ($desc) {
                break;
            }
        }
        if (!(str_contains($this->getSubfield($url, '3'),'Volltext'))) {
          $retVal[] = ['url' => $address, 'desc' => $desc ? $desc." (".$address.")" : $address];
        }
      }
    }
  }

  // Check 982 fields for UBM01 and links with no owner set (do not show links owned by other bib than UBM01!)
  $fieldsToCheck = [
      '982' => ['3', 'x', 'z', 'a','m']         //custom url, l=UBM01
  ];

  foreach ($fieldsToCheck as $field => $subfields) {
    $urls = $this->getMarcReader()->getFields($field);
    foreach ($urls as $url) {
      // Is there an address in the current field?
      $address = $this->getSubfield($url, 'u');
      if ($address) {
        foreach ($subfields as $current) {
            $desc = $this->getSubfield($url, $current);
            if ($desc) {
                break;
            }
        }
        //do not add fulltext urls in field 982
        $owner = $this->getSubfield($url, 'l');
        if (empty($owner)) {
          if(!((str_contains($this->getSubfield($url, 'x'),'Volltext'))||(str_contains($this->getSubfield($url, 'x'),'EZB'))||(str_contains($this->getSubfield($url, 'u'),'dbis')))) {
            $retVal[] = ['url' => $address, 'desc' => $desc ? $desc." (".$address.")" : $address];
          }
        }
      }
    }
  }

  return $retVal;
}
  /**
   * Support method for getJournalInfo() -- given a field specification, look for
   * journal information in the MARC record.
   *
   * @param array $fieldInfo Associative array of field => subfield information
   * (used to find journal information)
   *
   * @return array
   */
   protected function getJournalInfoFromMARC($fieldInfo)
   {
       $matches = [];

       // Loop through the field specification....
       foreach ($fieldInfo as $field => $subfields) {
           // Did we find any matching fields?
           $series = $this->getMarcReader()->getFields($field);
           foreach ($series as $currentField) {
             //check if we can find a stock in subfield c
             $stock = $this->getSubfields($currentField, 'c');
             //check if we can find a introductory text in subfield b
             $shelfmark_intro = $this->getSubfields($currentField, 'b');
             if (isset($stock[0])) {
               $currentArray['stock'] = isset($shelfmark_intro[0]) ? $shelfmark_intro[0] . ' ' . $stock[0] : $stock[0];
             }

             //check if we can find a inventory gap in field d
             $inventory_gap = $this->getSubfields($currentField, 'd');
             if (isset($inventory_gap[0])) {
               $currentArray['inventory_gap'] = $inventory_gap[0];
             } else {
               $currentArray['inventory_gap'] = "";
             }

             //check if we can find a shelfmark in subfield i
             $shelfmark = $this->getSubfields($currentField, 'i');
             //check if we can find a location code for future reference
             $locationCode = $this->getSubfields($currentField, 'h');
             if (isset($shelfmark[0])) {
                 $currentArray['shelfmark'] = $shelfmark[0];
             } else {
               //check if we can find a shelfmark in subfield g
               $shelfmark = $this->getSubfields($currentField, 'g');
               if (isset($shelfmark[0])) {
                 //if we find something in subfield g, add information from subfield h ($locationCode) in front
                   if(isset($locationCode[0])) {
                     $currentArray['shelfmark'] = $locationCode[0] . '/' . $shelfmark[0];
                   }
               }
             }

             //check if we can find a note in subfield f
             $note = $this->getSubfieldArray($currentField, ['f']);
             if (isset($note[0])) {
                 $currentArray['note'] = $note[0];
             } //remove value from currentArray if it is not set
             else {
               $currentArray['note'] = "";
             }

             //check if journal is in stock @ ZB = location code 0001
             if (str_starts_with($locationCode[0], '0001')) {
               $currentArray['note_id'] = $this->getUniqueID();
             } else {
               $currentArray['note_id'] ="";
             }

             //check if we can find a location code in subfield h to add a url
             $location_url = $this->getSubfields($currentField, 'h');
             if (isset($location_url[0])) {
                 $currentArray['url'] = $location_url[0];
             }
                   // Save the current match:
                 $matches[] = $currentArray;
             }

       }

       return $matches;
   }

    /**
   * Get an array of playing times for the record (if applicable).
   *
   * @return array
   */
  public function getPlayingTimes()
  {
      $times = $this->getFieldArray('306', ['a'], false);

      foreach ($times as $x => $time) {
      //Kanopy Wanderfalke starts with 'Duration' for PlayingTimes
      if (str_starts_with($time, 'Duration: ')) {
        $times = str_replace("Duration: ", "", $time);
      } else {
        // Format the times to include colons ("HH:MM:SS" format).
        if (!preg_match('/\d\d:\d\d:\d\d/', $time)) {
            $times[$x] = substr($time, 0, 2) . ':' .
                substr($time, 2, 2) . ':' .
                substr($time, 4, 2);
        }
      }
      }
      return $times;
  }


  /**
   * Support method for getSeries() -- given a field specification, look for
   * series information in the MARC record.
   *
   * @param array $fieldInfo Associative array of field => subfield information
   * (used to find series name)
   *d8UXSpCrP7UG7nYGczTa
   * @return array
   */
  protected function getCLDInfoFromMARC()
  {
      $cldfields = [
        'artikel-referenz-quelle' => $this->getUniqueID(),
        'artikel-isbn' => $this->getISBNs(),
        'artikel-issn' => $this->getISSNs(),
        'artikel-gesamt-titel' => $this->getShortTitle(),
        'artikel-gesamt-subtitel' => $this->getSubtitle(),
        'artikel-herausgeber' => $this->getPublishers(),
        'artikel-ausgabe' => [$this->getEdition()],
        'artikel-erscheinungsort' => $this->getPublicationPlace(),
        'artikel-erscheinungsjahr' => $this->getPublicationDates(),
        'artikel-gesamt-autor' => $this->getPrimaryAuthors(),
        'artikel-autor' => $this->getPrimaryAuthors(),
        'artikel-signatur' => $this->getSubfields(['945'], 'c'),
        'artikel-koerperschaft' => $this->getCorporateAuthors(),
        'zdb-id' => $this->getSubfields(['035'], 'a'),
      ];

      return $cldfields;
  }


  /**
   * Support method for getProvenance() -- look for provenance information in the MARC record.
   *
   * @return array
   */
   protected function getProvenance()
   {
     $provenance_list = [];
     $provenances = $this->getFieldArray('982', ['l']);
     foreach($provenances as $x => $provenance) {
       if(str_contains($provenance,'|')) {
         $provenance_list[$x] = $provenance;
       }
     }
     return $provenance_list;
   }

   /**
    * Support method for getProvenance() -- look for provenance information in the MARC record.
    *
    * @return array
    */
    protected function getStdNumber()
    {
      $stdnum_list = [];
      $stdnums = $this->getFieldArray('024', ['a']);
      foreach($stdnums as $x => $stdnum) {
        if(str_starts_with($stdnum, 'VD') || str_starts_with($stdnum, 'ISTC') || str_starts_with($stdnum, 'GW') || str_starts_with($stdnum, 'BSB') || str_starts_with($stdnum, 'HC')) {
          $stdnum_list[$x] = $stdnum;
        }
      }
      return $stdnum_list;
    }

    /**
     *
     * Return the type of continuing resource, needed for CLD check;
     *
     * @return string ZS info
     */

    public function isZS()
    {
      $zsinfo = false;
      $fields =  $this->getFieldArray('982', ['q']);
      foreach($fields as $x => $field) {
          if ($field == 'zs') {
              $zsinfo = true;
              break;
          }
      }
      return (bool)$zsinfo;
    }

    /**
     *
     * Return true if record is an UG or false if not
     *
     * @return bool UG
     */

    public function isUGFromMarc()
    {
      $isUG = false;
      $leader = $this->getMarcReader()->getLeader();
      //bibliographic level
      $biblioLevel = strtoupper($leader[7]);
      //multipart resource record level
      $mprrLevel = strtoupper($leader[19]);
      //check if resource is an UG or not
      if (($biblioLevel == 'S') || ($mprrLevel == 'A')) {
          $isUG = true;
      }
      // Check for content in following fields
      $fieldsToCheck = [
          '856' => ['u'],        //URL
          '982' => ['u'],        //URL
          '981' => ['h'],        //Bestandsuebersicht
          '981' => ['i']        //Bestandsuebersicht
      ];

      //do not show fields if there is a fulltext link or Signatur in Bestandsuebersicht
      foreach ($fieldsToCheck as $field => $subfields) {
        $contents = $this->getMarcReader()->getFields($field);
        foreach($contents as $content) {
          foreach ($subfields as $current) {
            $desc = $this->getSubfield($content, $current);
            if(!empty($desc)) {
              $isUG = false;
              break;
            }
          }
        }
      }
      //do not show fields if there is a fulltext link or Bestandsuebersicht
      //if(!(empty($content))) {
      //  $isUG = false;
      //}
      return (bool)$isUG;
    }

    /**
     *
     * Return the original title as string;
     *
     * @return string Original Title
     */
     public function getOriginalTitle()
     {
       $original_title = $this->getFirstFieldValue('240', ['a']);
       return (string)$original_title;
     }

}
