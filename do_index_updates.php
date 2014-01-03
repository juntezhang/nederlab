<?php
#@--------------------------------------------------------------------------------------
#@ By Junte Zhang <juntezhang@gmail.com> in 2013
#@ Distributed under the GNU General Public Licence
#@
#@ Do upgrades to the original index
#@--------------------------------------------------------------------------------------
mb_http_output("UTF-8");
ini_set('max_execution_time', 0);

require_once(dirname(__FILE__) .'../../../SolrPhpClient/Apache/Solr/Service.php' );
require_once(dirname(__FILE__) .'../../../SolrPhpClient/Apache/Solr/HttpTransport/CurlNoReuse.php');

$transportInstance = new Apache_Solr_HttpTransport_CurlNoReuse();
$core;

# only use the cmdi2 files, or else the XML parser will not work
$author_cmdi_path = "/Development/nederlab/data/cmdi2/dbnl_author";
$dep_titles_cmdi_path = "/Development/nederlab/data/cmdi2/dbnl_doc_onzelfstandig";
$independent_titles_cmdi_path = "/Development/nederlab/data/cmdi2/dbnl_doc";
$independent_titles_only_cmdi_path = "/Development/nederlab/data/cmdi2/dbnl_title";

if(isset($_GET["core"])) 
{
  $core = $_GET['core'];
}
else 
{
  echo "No core name provided. Please provide one. Exiting script.";
	exit;  
}

#----------------------------------------------------
# Solr instantion. Note: I have tested it only local!
#----------------------------------------------------
$server = "localhost";
#$server = "openskos.meertens.knaw.nl";
#$server = "145.100.58.246";

$solr = new Apache_Solr_Service( $server, '8983', "/solr/$core/", $transportInstance );
$solr->setHttpTransport($transportInstance);

#------------------------------------------------
# Call to main 
#------------------------------------------------
main();

#------------------------------------------------
# Read update files and update to index
#------------------------------------------------
function main() 
{
  # I have not set this, because not all texts have a title metadata record! This is the reason why there is a separate DBNL_Tekst record type (profile)
  #merge_title_and_text();
  
  update_dependent_titles("/Development/nederlab/data/updates/onz_titels.xml");
  update_independent_titles("/Development/nederlab/data/updates/titels.xml");
  update_authors("/Development/nederlab/data/updates/auteurs.xml");
}

#------------------------------------------------
# Functions
#------------------------------------------------

# this function merges the full text of TEI files with the title metadata records
function merge_title_and_text()
{
  global $solr, $server, $independent_titles_cmdi_path, $independent_titles_only_cmdi_path;
  
  $files_txt = glob("$independent_titles_cmdi_path/*.xml");
  
  # get rid of the _01 appendix to get the id of the title id
  foreach ($files_txt as $file_txt) 
  {
    if(preg_match("/.*\/(.+)\_01.xml/", $file_txt, $matches)) 
    {
      try 
      {        
        $response = $solr->search('MdSelfLink:'.$matches[1].'', '0', '1', null, Apache_Solr_Service::METHOD_POST);

        # check if it is an existing record
        if(sizeof($response->response->docs) > 0)
        {          
          $doc_upd = new Apache_Solr_Document();
          $nederl_metadata = "";
          
          $doc_upd->addField("MdSelfLink", $matches[1]);
          $nederl_metadata .= " " . $matches[1];
                   
          foreach ($response->response->docs as $element) 
          {
            foreach ($element as $field => $value) 
            {
              # do not output old values
              if($field == "MdSelfLink")
              {
                ;
              }
              else 
              {
                if(is_array($value)) 
                {
                  for($cnt = 0 ; $cnt < sizeof($value) ; $cnt++) {  
                    $doc_upd->addField($field, $value[$cnt]);
                    $nederl_metadata .= " " . $value[$cnt];
                  }
                }
                else 
                {
                  $doc_upd->addField($field, $value);
                  $nederl_metadata .= " " . $value;
                }
              }
            }
          }     
              
          # add fulltext and #pages #tokens  
          $doc = read_update_file($file_txt);
          $doc = utf8_for_xml($doc);          
          $xml_str = simplexml_load_string($doc);

          
          # "new" fields to be indexed
          $txt_content = $xml_str->xpath("//tekst_inhoud");
          foreach ($txt_content as $txt) 
          {
            $doc_upd->addField("nederl_content", $txt);
          }         

          $extent_1 = $xml_str->xpath("//extent[1]");
          $extent_2 = $xml_str->xpath("//extent[2]");
          foreach ($extent_1 as $tokens) 
          {
            $doc_upd->addField("nederl_extent_tokens_order", $tokens);
          }
          foreach ($extent_2 as $pages) 
          {
            $doc_upd->addField("nederl_extent_pages_order", $tokens);
          }
          # add all metadata values to a single field in the index
          $doc_upd->addField("nederl_metadata", $nederl_metadata);    
          
          # add the Solr document to the index
          add_to_index($doc_upd, $solr);          
        }                
      }
      catch (Exception $e) 
      {
        die($e->__toString());
      }    
    }
  }

  # commit as last to push the documents to the index
  commit_to_index($solr);
}

# this function updates the dependent titles: it consists of heuristics and mappings
function update_dependent_titles($file)
{
  global $solr, $author_cmdi_path, $server, $dep_titles_cmdi_path, $core;
  
  $doc = read_update_file($file);
  $doc = utf8_for_xml($doc);

  $xml_str = simplexml_load_string($doc);

  $values = $xml_str->xpath("//doc");

  foreach ($values as $val) 
  {
    $doc_upd = new Apache_Solr_Document();
    $year_publication;
    $response;
    $nederl_metadata = '';
    
    $flag_order = 0;
    foreach ($val as $key => $val_sub) 
    {                       
      switch(true)
      {
        # MdSelfLink
        case(preg_match("/nl_MdSelfLink/", $key)) :
          try {
            $response = $solr->search('MdSelfLink:'.$val_sub.'', '0', '1', null, Apache_Solr_Service::METHOD_POST);

            # check if it is an existing record
            if(sizeof($response->response->docs) > 0)
            {
              $doc_upd->addField("MdSelfLink", $val_sub);
              $nederl_metadata .= " " . $val_sub;
              
              # get year of publication from index           
              foreach ($response->response->docs as $element) 
              {
                foreach ($element as $field => $value) 
                {
                  if($field == "DC-2538")
                  {
                    $year_publication = $value;
                    $doc_upd->addField($field, $value);
                    
                    $time_order;
                    if(preg_match("/^.*\s*(\d{4}).*/", $value, $matches)) 
                    {
                      $time_order = $matches[1] . "-00-00T00:00:00Z";
                    }
                    else
                    {
                      $time_order = "0000-00-00T00:00:00Z";
                    }
                    $doc_upd->addField("nederl_time_order", $time_order);    
                              
                    $nederl_metadata .= " " . $value;
                  }
                  # do not print out the old values
                  else if(($field == "MdSelfLink") || ($field == "resourcetype") || ($field == "resourceref") || ($field == "title") || ($field == "auteur_id") || ($field == "DC-4194") || ($field == "voorvoegsel") || ($field == "DC-4195") || ($field == "titlestmt.author") || ($field == "DC-5484") || ($field == "leeftijd_bij_publicatie") || ($field == "DC-5619") || ($field == "DC-3660") || ($field == "geb_plaats") || ($field == "overl_plaats") || ($field == "vrouw") || ($field == "beroep") || ($field == "nederl_date_modified") || ($field == "nederl_metadata") || ($field == "fulltext") || ($field == "nederl_reference")) 
                  {
                    ;
                  }
                  else 
                  {
                    if(is_array($value)) 
                    {
                      for($cnt = 0 ; $cnt < sizeof($value) ; $cnt++) {  
                        $doc_upd->addField($field, $value[$cnt]);
                        $nederl_metadata .= " " . $value[$cnt];
                      }
                    }
                    else 
                    {
                      $doc_upd->addField($field, $value);
                      $nederl_metadata .= " " . $value;
                    }
                  }
                }
              }          
            }
            # else do not add it to the index and ignore it
            else
            {
              continue;
            }          
          }
          catch (Exception $e) {
            die($e->__toString());
          }

        break;
    
        # title
        case(preg_match("/nl_deptitel/", $key)) :
          $doc_upd->addField("title", $val_sub);
          
          $doc_upd->addField("nederl_title_order", $val_sub);
          
          $nederl_metadata .= " " . $val_sub;
        break;     
      
        # auteur_id
        case(preg_match("/nl_auteur/", $key)) :
          foreach ($val_sub as $key => $val_sub_sub) 
          {
            $doc_upd->addField("auteur_id", $val_sub_sub);
            $nederl_metadata .= " " . $val_sub_sub;
            
            # if there is as least 1 author
            if(strlen($val_sub_sub) > 0)
            {
              $doc_author = read_update_file($author_cmdi_path . "/" . $val_sub_sub . ".xml");
          
              $xml_str_author = simplexml_load_string($doc_author);
          
              #----------------------------------------
              # re-calculate author information
              #----------------------------------------            
              $titlestmt_author = "";
                      
              # DC-4194 voornaam
              $values_author_firstname = $xml_str_author->xpath("//voornaam");
              $titlestmt_author = join(" ", $values_author_firstname);
          
              # voorvoegsel
              $values_author_prefix = $xml_str_author->xpath("//voorvoegsel");
              foreach ($values_author_prefix as $prefix_val) 
              {
                if(strlen($prefix_val) > 0)
                {
                  $titlestmt_author .= " " . join(" ", $values_author_prefix);
                }
              }
                      
              # DC-4195 achternaam
              $values_author_lastname = $xml_str_author->xpath("//achternaam");
              $titlestmt_author .= " " . join(" ", $values_author_lastname);
                      
              # titlestmt.author first name + voorvoegsel + last name
              $doc_upd->addField("titlestmt.author", $titlestmt_author);
              $nederl_metadata .= " " . $titlestmt_author;
                      
              # DC-5484 jaar_geboren
              $values_author_year_birth = $xml_str_author->xpath("//jaar_geboren");           
          
              # leeftijd_bij_publicatie: publication year - year of birth 
              if(($year_publication > join(" ", $values_author_year_birth)) && (join(" ", $values_author_year_birth) > 0)) 
              {
                $age_at_publication = $year_publication - join(" ", $values_author_year_birth);
                $doc_upd->addField("leeftijd_bij_publicatie", $age_at_publication);
                $nederl_metadata .= " " . $age_at_publication;
              }
              else 
              {
                $doc_upd->addField("leeftijd_bij_publicatie", "");
              }
                      
              # DC-5619 jaar_overlijden
              $values_author_year_death = $xml_str_author->xpath("//jaar_overlijden");  
                            
              $flag_time_end = 1;
              
              # DC-3660 age
              $age = join(" ", $values_author_year_death) - join(" ", $values_author_year_birth); 
              if($age > 0)
              {
                $doc_upd->addField("DC-3660", $age);
                $nederl_metadata .= " " . $age;
              }
              else 
              {
                $doc_upd->addField("DC-3660", "");
              }
          
              if($flag_order == 0)
              {
                $doc_upd->addField("nederl_author_order", join(" ", $values_author_lastname));
                
                $start_time;
                if(preg_match("/^\s*(\d+).*/", join(" ", $values_author_year_birth), $matches)) 
                {
                  $start_time = $matches[1];
                }
                else
                {
                  $start_time = "0";
                }                
                $doc_upd->addField("nederl_time_start_order", $start_time);
                
                $end_time;
                if(preg_match("/^\s*(\d+).*/", join(" ", $values_author_year_death), $matches)) 
                {
                  $end_time = $matches[1];
                }
                else
                {
                  $end_time = "0";
                }                  
                $doc_upd->addField("nederl_time_end_order", $end_time);   
              }
              $flag_order = 1;
            }
            # there is no author, so add empty fields for consistency
            else
            {
              $doc_upd->addField("titlestmt.author", "");
              $doc_upd->addField("leeftijd_bij_publicatie", "");
              $doc_upd->addField("DC-3660", "");
              
              $doc_upd->addField("nederl_author_order", "");
              $doc_upd->addField("nederl_time_start_order", "0");
              $doc_upd->addField("nederl_time_end_order", "0");              
            }
          }             
        break;

        case(preg_match("/nl_referentie/", $key)) :
          $reference = "";
          $reference_cnt = 0;
          foreach($val_sub_sub as $key_sub => $val_sub_sub_sub)
          {
            if($val_sub_sub_sub != "")
            {
              if($reference_cnt == 0)
              {
                $reference .= $val_sub_sub_sub;
              }
              else 
              {
                $reference .= " " . $val_sub_sub_sub;
              }
            }          
            $reference_cnt++;
          }
          $doc_upd->addField("nederl_reference", $reference);
          $nederl_metadata .= " " . $reference;
        break;  
              
        # nederl_date_modified
        case(preg_match("/nl_datum_opname/", $key)) :
          $timestamp = convert_date_to_iso($val_sub);
          $doc_upd->addField("nederl_date_modified", $timestamp);
          $nederl_metadata .= " " . $timestamp;
        break;
      }
    } 
    
    # add all metadata values to a single field in the index
    if($core == "dbnl_metadata")
    {
      $doc_upd->addField("fulltext", $nederl_metadata);
    }
    else 
    {
      $doc_upd->addField("nederl_metadata", $nederl_metadata);
    }
       
    # add the Solr document to the index
    add_to_index($doc_upd, $solr);
  }
  # commit as last to push the documents to the index
  commit_to_index($solr);
}

function update_independent_titles($file)
{
  global $solr, $server, $independent_titles_cmdi_path, $core;

  $doc = read_update_file($file);
  $doc = utf8_for_xml($doc);
  
  $xml_str = simplexml_load_string($doc);

  $values = $xml_str->xpath("//doc");

  foreach ($values as $val) 
  {
    $doc_upd = new Apache_Solr_Document();
    $response;
    $nederl_metadata = '';
    foreach ($val as $key => $val_sub) 
    {
      switch(true)
      {
        # MdSelfLink
        case(preg_match("/nl_MdSelfLink/", $key)) :
          try 
          {
            $response = $solr->search('MdSelfLink:'.$val_sub.'', '0', '1', null, Apache_Solr_Service::METHOD_POST);

            # check if it is an existing record
            if(sizeof($response->response->docs) > 0)
            {            
              $doc_upd->addField("MdSelfLink", $val_sub);
              $nederl_metadata .= " " . $val_sub;
                         
              foreach ($response->response->docs as $element) 
              {
                foreach ($element as $field => $value) 
                {
                  # do not output old values
                  if(($field == "MdSelfLink") || ($field == "resourcetype") || ($field == "resourceref") || ($field == "auteur_id") || ($field == "DC-2545") || ($field == "DC-2470") || ($field == "DC-3899") || ($field == "DC-2538") || ($field == "subtitel") || ($field == "nederl_date_modified") || ($field == "nederl_reference") || ($field == "nederl_metadata") || ($field == "fulltext"))
                  {
                    ;
                  }
                  else 
                  {
                    if(is_array($value)) 
                    {
                      for($cnt = 0 ; $cnt < sizeof($value) ; $cnt++) {  
                        $doc_upd->addField($field, $value[$cnt]);
                        $nederl_metadata .= " " . $value[$cnt];
                      }
                    }
                    else 
                    {
                      $doc_upd->addField($field, $value);
                      $nederl_metadata .= " " . $value;
                    }
                  }
                }
              }            
            }
            # do not add it to the index
            else 
            {
              continue;
            }
          }
          catch ( Exception $e ) 
          {
            echo $e->getMessage();
          }
        break;
        
        # title
        case(preg_match("/nl_DC_2545/", $key)) :
          $doc_upd->addField("DC-2545", $val_sub);
          
          $doc_upd->addField("nederl_title_order", $val_sub);
          
          $nederl_metadata .= " " . $val_sub;
        break;     
        
        # genre
        case(preg_match("/nl_DC_2470/", $key)) :
          $doc_upd->addField("DC-2470", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;     
        
        # subgenre
        case(preg_match("/nl_DC_3899/", $key)) :
          $doc_upd->addField("DC-3899", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;          
        
        # jaar
        case(preg_match("/nl_DC_2538/", $key)) :
          $doc_upd->addField("DC-2538", $val_sub);

          $time_order;
          if(preg_match("/^.*\s*(\d{4}).*/", $val_sub, $matches)) 
          {
            $time_order = $matches[1] . "-00-00T00:00:00Z";
          }
          else
          {
            $time_order = "0000-00-00T00:00:00Z";
          }
          $doc_upd->addField("nederl_time_order", $time_order);  
                              
          $nederl_metadata .= " " . $val_sub;
        break;    

        # auteur_id
        case(preg_match("/nl_auteur/", $key)) :
          foreach ($val_sub as $key => $val_sub_sub) 
          {
            $doc_upd->addField("auteur_id", $val_sub_sub);
            $nederl_metadata .= " " . $val_sub_sub;
          }
          
          # note for future work: it is possible to lookup the lastname of first author in the CMDI file and use it for nederl_author_order in titles
        break;
        
        # subtitel
        case(preg_match("/nl_subtitel/", $key)) :
          $doc_upd->addField("subtitel", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;

        case(preg_match("/nl_referentie/", $key)) :
          $reference = "";
          $reference_cnt = 0;
          foreach($val_sub_sub as $key_sub => $val_sub_sub_sub)
          {
            if($val_sub_sub_sub != "")
            {
              if($reference_cnt == 0)
              {
                $reference .= $val_sub_sub_sub;
              }
              else 
              {
                $reference .= " " . $val_sub_sub_sub;
              }
            }          
            $reference_cnt++;
          }
          $doc_upd->addField("nederl_reference", $reference);
          $nederl_metadata .= " " . $reference;
        break;  
                                                  
        # nederl_date_modified
        case(preg_match("/nl_datum_opname/", $key)) :
          $timestamp = convert_date_to_iso($val_sub);
          $doc_upd->addField("nederl_date_modified", $timestamp);
          $nederl_metadata .= " " . $timestamp;
        break;                            
      }
    }
    # add all metadata values to a single field in the index
    if($core == "dbnl_metadata")
    {
      $doc_upd->addField("fulltext", $nederl_metadata);
    }
    else 
    {
      $doc_upd->addField("nederl_metadata", $nederl_metadata);
    }
           
    # add the Solr document to the index
    add_to_index($doc_upd, $solr);
  }
  # commit as last to push the documents to the index
  commit_to_index($solr);  
}


function update_authors($file)
{
  global $solr, $core, $author_cmdi_path, $core;

  $doc = read_update_file($file);
  $doc = utf8_for_xml($doc);

  $xml_str = simplexml_load_string($doc);

  $values = $xml_str->xpath("//doc");

  $del_author_ids = array();
  foreach ($values as $val) 
  {
    $doc_upd = new Apache_Solr_Document();
    $response;
    $nederl_metadata = '';
    $MdSelfLink = '';
    foreach ($val as $key => $val_sub) 
    {  
      switch(true)
      {
        # MdSelfLink
        case(preg_match("/nl_MdSelfLink/", $key)) :
          try 
          {
            $response = $solr->search('MdSelfLink:'.$val_sub.'', '0', '1', null, Apache_Solr_Service::METHOD_POST);

            # check if it is an existing record
            if(sizeof($response->response->docs) > 0)
            {            
              $doc_upd->addField("MdSelfLink", $val_sub);
              $MdSelfLink = $val_sub;
              $nederl_metadata .= " " . $val_sub;
                            
              foreach ($response->response->docs as $element) 
              {
                foreach ($element as $field => $value) 
                {
                  # do not output old values or values that have become obsolete
                  if(($field == "MdSelfLink") || ($field == "resourcetype") || ($field == "resourceref") || ($field == "DC-4195") || ($field == "DC-4194") || ($field == "voorvoegsel") || ($field == "voornaam_volledig") || ($field == "geb_datum") || ($field == "DC-5484") || ($field == "geb_plaats") || ($field == "geb_plaats_code") || ($field == "overl_datum") || ($field == "DC-5619") || ($field == "overl_plaats") || ($field == "overl_plaats_code") || ($field == "beroep") || ($field == "vrouw") || ($field == "naam_variant") || ($field == "nederl_note_editor") || ($field == "nederl_reference") || ($field == "nederl_date_modified") || ($field == "nederl_metadata") || ($field == "fulltext"))
                  {
                    ;
                  }
                  else 
                  {
                    if(is_array($value)) 
                    {
                      for($cnt = 0 ; $cnt < sizeof($value) ; $cnt++) {  
                        $doc_upd->addField($field, $value[$cnt]);
                        $nederl_metadata .= " " . $value[$cnt];
                      }
                    }
                    else 
                    {
                      $doc_upd->addField($field, $value);
                      $nederl_metadata .= " " . $value;
                    }
                  }
                }
              }            
            }
            # do not add it to the index. if there is a new record, then remove contineu
            else 
            {
              continue;
            }
          }
          catch ( Exception $e ) 
          {
            echo $e->getMessage();
          }
        break;
        
        case(preg_match("/nl_DC_4195/", $key)) :
          $doc_upd->addField("DC-4195", $val_sub);
          
          $doc_upd->addField("nederl_author_order", $val_sub);
          
          $nederl_metadata .= " " . $val_sub;
        break;
        
        case(preg_match("/nl_DC_4194/", $key)) :
          $doc_upd->addField("DC-4194", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;

        case(preg_match("/nl_voorvoegsel/", $key)) :
          $doc_upd->addField("voorvoegsel", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;                

        case(preg_match("/nl_volledige_voornaam/", $key)) :
          $doc_upd->addField("voornaam_volledig", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;            
        
        case(preg_match("/nl_geb_datum/", $key)) :
          $doc_upd->addField("geb_datum", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;

        case(preg_match("/nl_DC_5484/", $key)) :
          $doc_upd->addField("DC-5484", $val_sub);
          
          $time_start;
          if(preg_match("/^\s*(\d+).*/", $val_sub, $matches)) 
          {
            $time_start = $matches[1];
          }
          else
          {
            $time_start = "0";
          }
          $doc_upd->addField("nederl_time_start_order", $time_start); 
          
          $nederl_metadata .= " " . $val_sub;
        break;                   

        case(preg_match("/nl_geboorteplaats/", $key)) :
          $doc_upd->addField("geb_plaats", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;          
        
        case(preg_match("/nl_overl_datum/", $key)) :
          $doc_upd->addField("overl_datum", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;  

        case(preg_match("/nl_jaar_overlijden/", $key)) :
          # this heuristic can be deleted if Rob replaces field "jaar_overlijden" with DC-5619 in the editor
          if($core == "dbnl_metadata")
          {
            $doc_upd->addField("jaar_overlijden", $val_sub);                      
            $nederl_metadata .= " " . $val_sub;
          }
          else
          {
            $doc_upd->addField("DC-5619", $val_sub);
            $nederl_metadata .= " " . $val_sub;
          }

          $time_end;
          if(preg_match("/^\s*(\d+).*/", $val_sub, $matches)) 
          {
            $time_end = $matches[1];
          }
          else
          {
            $time_end = "0";
          }
          $doc_upd->addField("nederl_time_end_order", $time_end); 
                      
        break;  
        
        case(preg_match("/nl_overl_plaats/", $key)) :
          $doc_upd->addField("overl_plaats", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;                            
        
        case(preg_match("/nl_beroep/", $key)) :
          foreach ($val_sub as $key => $val_sub_sub) 
          {
            $doc_upd->addField("beroep", $val_sub_sub);
            $nederl_metadata .= " " . $val_sub_sub;
          }
        break; 
        
        case(preg_match("/nl_geslacht/", $key)) :
          $doc_upd->addField("vrouw", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break;     

        case(preg_match("/nl_geslacht/", $key)) :
          $doc_upd->addField("vrouw", $val_sub);
          $nederl_metadata .= " " . $val_sub;
        break; 

        # new field extra added
        case(preg_match("/nl_opleiding/", $key)) :
          foreach ($val_sub as $key => $val_sub_sub) 
          {
            $doc_upd->addField("opleiding", $val_sub_sub);
            $nederl_metadata .= " " . $val_sub_sub;
          }
        break;   
        
        case(preg_match("/nl_naam_variant/", $key)) :
          foreach ($val_sub as $key => $val_sub_sub) 
          {
            $naam_variant = "";
            foreach($val_sub_sub as $key_sub => $val_sub_sub_sub)
            {
              if($val_sub_sub_sub != "")
              {
                $naam_variant .= " " . $val_sub_sub_sub;
              }          
            }
            $doc_upd->addField("naam_variant", $naam_variant);
            $nederl_metadata .= " " . $naam_variant;
          }
        break;     
        
        case(preg_match("/nl_referentie/", $key)) :
          foreach ($val_sub as $key => $val_sub_sub) 
          {
            $reference = "";
            $reference_cnt = 0;
            foreach($val_sub_sub as $key_sub => $val_sub_sub_sub)
            {
              if($val_sub_sub_sub != "")
              {
                if($reference_cnt == 0)
                {
                  $reference .= $val_sub_sub_sub;
                }
                else 
                {
                  $reference .= " " . $val_sub_sub_sub;
                }
              }          
              $reference_cnt++;
            }
            $doc_upd->addField("nederl_reference", $reference);
            $nederl_metadata .= " " . $reference;
          }
        break;  
                
        case(preg_match("/nl_opmerkingen/", $key)) :
          # Regels van Rob: Het gaat uitsluitend om de auteurs. Bij de dubbele is daar in het opmerkingen-veld een '='-teken geplaatst. Als dat teken ook nog eens vergezeld is van een of twee vraagtekens, dan is het nog niet zeker en moet dat record blijven staan.
          if(preg_match("/^=(.+)/", $val_sub, $matches))
          {
            if(preg_match("/\?\?/", $matches[1]))
            {
              $doc_upd->addField("nederl_note_editor", $val_sub);
              $nederl_metadata .= " " . $val_sub;            
            }
            else
            {
              # it is a duplicate record, so delete it
              $doc_upd->addField("nederl_note_editor", $val_sub);
              
              array_push($del_author_ids, $MdSelfLink); 
              continue;
            }
          }
          else 
          {
            $doc_upd->addField("nederl_note_editor", $val_sub);
            $nederl_metadata .= " " . $val_sub;
          }
        break;                                        
      }    
    }
    # add all metadata values to a single field in the index
    if($core == "dbnl_metadata")
    {
      $doc_upd->addField("fulltext", $nederl_metadata);
    }
    else 
    {
      $doc_upd->addField("nederl_metadata", $nederl_metadata);
    }
    
    # add the Solr document to the index
    add_to_index($doc_upd, $solr);        
  }
  # commit as last to push the documents to the index
  commit_to_index($solr);    
  
  # delete duplicate IDs
  foreach ($del_author_ids as $id)
  { 
    $solr->deleteById($id); 
  }
  commit_to_index($solr);  
}    

function convert_date_to_iso($date)
{
  return date('Y-m-d\TH:i:s', strtotime($date)) . "Z";
}

function get_nederl_content($doc_upd, $dir, $id) 
{  
  $doc = read_update_file($dir . "/" . $id . ".xml");
  if($doc == "no file found")
  {
    return;
  }
  
  $xml_str = simplexml_load_string($doc);
  $values = $xml_str->xpath("//Components");
  
  foreach ($values as $val) 
  {
    foreach ($val as $key => $content) 
    {
      switch(true)
      {
        case(preg_match("/tekst_inhoud/", $key)) :
          $doc_upd->addField("nederl_content", $content);
        break;
      }
    }
  }
}

function read_update_file($file)
{
  if(!file_exists($file))
  {
    return "no file found";
  }
  else
  { 
    $lines = file_get_contents($file);
  
    if ($lines === false) {
      return "no file found";
      #throw new Exception('Failed to open ' . $file);
    } else {
      return $lines; 
    }		
  }
}

function add_to_index($doc_upd, $solr)
{
  # add the Solr document to the index but not if it has no MdSelfLink
  if(isset($doc_upd->MdSelfLink)) {
    try {
      $solr->addDocument( $doc_upd );
    }
    catch ( Exception $e ) {
      echo $e->getMessage();
    } 
  }
}

function commit_to_index($solr)
{
  # modify the Service.php of this library to get rid of the waitflush message, because it is deprecated since Solr 4
  try 
  {
    $solr->commit();
  }
  catch ( Exception $e ) 
  {
    echo $e->getMessage();
  }
}

function utf8_for_xml($string)
{
    return preg_replace ('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $string);
}

#------------------------------------------------
# Thrash
#------------------------------------------------


?>