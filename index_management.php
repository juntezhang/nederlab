<?php
#@---------------------------------------------------------------------------------------
#@ Created by Junte Zhang <juntezhang@gmail.com> in 2013
#@ Distributed under the GNU General Public Licence
#@
#@ This script indexes DBNL data to Solr, make sure to put it in a Webserver directory
#@---------------------------------------------------------------------------------------
ini_set("memory_limit","3G");
ini_set("max_execution_time", 0);

#--------------------------------------------------------------
# global variables 
#--------------------------------------------------------------
$status = '';
$dir = '';
$server = '';
$post_string = '';

# this is just an empty XML file on a webserver to speed things up
$file = "/Library/WebServer/Documents/solr/nederlab/editRecord/empty.xml";

$core_original = "dbnl";
$core_new = "dbnl_" . date('Y-m-d');

$url_original_core;
$url_new_core;
$url_reload_original_core;
$url_reload_new_core;
$url_create;
$url_remove;

$instance_dir = "/Development/apache-solr-4.4.0/example/solr/dbnl";

#-------------------------------------------------------------------------
# status: update, reload, delete, create
#-------------------------------------------------------------------------
if(!isset($_GET["status"])) 
{
  $status = "update_new_core";
}
else 
{
  $status = $_GET["status"];
}

#-------------------------------------------------------------------------
# data: make sure the path is correct, default the dir is of all files
#-------------------------------------------------------------------------
if(!isset($_GET["data"])) 
{
  $dir = "/Development/nederlab/scripts/indexData";
  #$dir = "/Development/nederlab/scripts/indexData_dbnl";
  #$dir = "/Development/nederlab/scripts/indexDataTest";
  #$dir = "/Development/nederlab/scripts/indexData_dbnl_onlymetadata";
  #$dir = "/Development/nederlab/scripts/indexData_dbnl_metadata_content";
  #$dir = "/Development/nederlab/scripts/indexData_dbnl_titles_authors";
}
else 
{
  $dir = $_GET["data"];
}

#--------------------------------------------------------------
# setting the Solr server, directory and filename of data 
# server:  local, openskos, production
#--------------------------------------------------------------
if(!isset($_GET["server"])) 
{
  $server = "local";
}
else 
{
  $server = $_GET["server"];
}

switch($server)
{
  case 'local':  
    $domain = "http://localhost:8983/solr";
    
    $url_original_core = "$domain/$core_original/update";
    $url_new_core = "$domain/$core_new/update";

    $url_reload_original_core = "$domain/admin/cores?action=RELOAD&core=$core_original";
    $url_reload_new_core = "$domain/admin/cores?action=RELOAD&core=$core_new";

    $url_create = "$domain/admin/cores?action=CREATE&name=$core_new&instanceDir=$instance_dir";
    $url_remove = "$domain/admin/cores?action=UNLOAD&core=$core_new";
  break;
  
  case 'openskos':
    $domain = "http://openskos.meertens.knaw.nl/solr";
    
    $url_original_core = "$domain/$core_original/update";
    $url_new_core = "$domain/$core_new/update";

    $url_reload_original_core = "$domain/admin/cores?action=RELOAD&core=$core_original";
    $url_reload_new_core = "$domain/admin/cores?action=RELOAD&core=$core_new";

    $url_create = "$domain/admin/cores?action=CREATE&name=$core_new&instanceDir=$instance_dir";
    $url_remove = "$domain/admin/cores?action=UNLOAD&core=$core_new";  
  break;
  
  case 'production':
    $domain = "http://145.100.58.246/solr";
    
    $url_original_core = "$domain/$core_original/update";
    $url_new_core = "$domain/$core_new/update";

    $url_reload_original_core = "$domain/admin/cores?action=RELOAD&core=$core_original";
    $url_reload_new_core = "$domain/admin/cores?action=RELOAD&core=$core_new";

    $url_create = "$domain/admin/cores?action=CREATE&name=$core_new&instanceDir=$instance_dir";
    $url_remove = "$domain/admin/cores?action=UNLOAD&core=$core_new";    
  break;
  
  default:
    $domain = "http://localhost:8983/solr";
    
    $url_original_core = "$domain/$core_original/update";
    $url_new_core = "$domain/$core_new/update";

    $url_reload_original_core = "$domain/admin/cores?action=RELOAD&core=$core_original";
    $url_reload_new_core = "$domain/admin/cores?action=RELOAD&core=$core_new";

    $url_create = "$domain/admin/cores?action=CREATE&name=$core_new&instanceDir=$instance_dir";
    $url_remove = "$domain/admin/cores?action=UNLOAD&core=$core_new";  
}  

  
#--------------------------------------------------------------
# do something with Solr given the status value 
#--------------------------------------------------------------	
switch($status)
{
  case 'update_original_core':
    $url =  $url_original_core . "?commit=true";
    core_action_with_dir($url);
  break;

  case 'update_new_core':
    $url =  $url_new_core . "?commit=true";
    core_action_with_dir($url);
  break;
    
  case 'reload_original_core':
    $url =  $url_reload_original_core;
    core_action_with_single_file($url);
  break;

  case 'reload_new_core':
    $url =  $url_reload_new_core;
    core_action_with_single_file($url);
  break;
    
  case 'create_new_core':
    $url =  $url_create;
    core_action_with_single_file($url);  
  break;

  case 'delete_new_core':
    $url =  $url_remove;
    core_action_with_single_file($url);  
  break;
  
  case 'empty_new_core':
    $url = $url_new_core . "?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E&commit=true";  
    core_action_with_single_file($url);
  break;
    
  case 'empty_original_core':
    $url = $url_original_core . "?stream.body=%3Cdelete%3E%3Cquery%3E*:*%3C/query%3E%3C/delete%3E&commit=true";  
    core_action_with_single_file($url);
  break;
  
  case 'optimize_new_core':
    $url = $url_new_core . "?optimize=true";
    core_action_with_single_file($url);
  break;

  case 'optimize_original_core':
    $url = $url_original_core . "?optimize=true";
    core_action_with_single_file($url);
  break;
    
  default:
    $url =  $url . "?commit=true";
    core_action_with_dir($url);
}

function core_action_with_dir($url)
{
  global $dir, $file, $post_string;
    
  $files = glob("$dir/*.xml");
  array_multisort(
          array_map( 'filesize', $files ),
          SORT_NUMERIC,
          SORT_ASC,
          $files
      );
  array_push($files, $files[0]);
  array_shift($files);

  foreach ($files as $file) 
  {
    $post_string = file_get_contents($file);
    $header = array("Content-type:text/xml; charset=utf-8");

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLINFO_HEADER_OUT, 1);

    $dat = curl_exec($ch);
    print $dat;
  
    if (curl_errno($ch)) 
    {
       print "curl_error:" . curl_error($ch);
    }
    else 
    {
       curl_close($ch);
    }
  }
}

function core_action_with_single_file($url)
{
  global $file, $post_string;
  
  $post_string .= file_get_contents($file);

  $header = array("Content-type:text/xml; charset=utf-8");

  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
  curl_setopt($ch, CURLOPT_TIMEOUT, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
  curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
  curl_setopt($ch, CURLINFO_HEADER_OUT, 1);

  $dat = curl_exec($ch);
  print $dat;

  if (curl_errno($ch)) 
  {
     print "curl_error:" . curl_error($ch);
  }
  else 
  {
     curl_close($ch);
  }
}

?>