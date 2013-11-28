#!/bin/bash
#@--------------------------------------------------------------------------------
#@ Created by Junte Zhang <juntezhang@gmail.com> in 2013
#@ Distributed under the GNU General Public Licence
#@
#@ This shell script automates the CMDI MI indexing procedure 
#@---------------------------------------------------------------------------------

# store start time
START=$(date +%s)
today="$(date +'%Y-%m-%d')"

#--------------------------------------------------------------
# declare variables
#--------------------------------------------------------------
# commandline arguments
flag=$1
mode=$2
   
# Paths
solr=/Development/apache-solr-4.4.0 # Solr installation
script_path=/Development/nederlab/scripts # directory with this script
web_js_path=/Library/WebServer/Documents/solr/nederlab/js # directory on webserver to store the field labels (for CMDI MI UI)
index_management_path=http://localhost/solr/nederlab/editRecord # directory on webserver with the PHP scripts
solr_core_path=$solr/example/solr/dbnl # original directory, do not mess with this core!
solr_new_core_path=$solr/example/solr/dbnl_$today # directory of new core
solr_new_core_config_path=$solr/example/solr/dbnl_$today/conf # directory with configuration files of new core

# JS
labels4user=$web_js_path/cmdi.labels.js

# PHP
profilenames=$script_path/cmdi-profile-names.php

# Perl
zt=$script_path/convert2cmdi_ft.pl
ozt=$script_path/convert2cmdi_ft_ot.pl
createlist=$script_path/cmdi-list-per-schema.pl
cmdi2xslt=$script_path/cmdi-xsl-per-schema.pl
cmdi2schema=$script_path/cmdi-data-per-schema.pl
schema2solr=$script_path/cmdi-schemas2solr.pl
labels2solr=$script_path/cmdi-labels4solr.pl

# XSLT
saxon=/Software/saxonb9-1-0-8j/saxon9.jar

#------------------------------------------------------------------------------------------------------------
# if 2nd argv has value "new", then create a new core and do indexing on new core, or else default behavior
#------------------------------------------------------------------------------------------------------------
if test "$mode" == "new"; then  
  # re-write the paths to the new core
  reload_core="reload_new_core"
  empty_core="empty_new_core"
  update_core="update_new_core"
  optimize_core="optimize_new_core"

  # XML
  schema=$solr_new_core_config_path/schema.xml

  # if core exists, then delete
  if [ -d "$solr_new_core_path" ]; then
    echo ''
    echo 'Solr core already exists, so cleaning up first'

    curl $index_management_path/index_management.php?status=$empty_core&server=local
    
    wait
    
    curl $index_management_path/index_management.php?status=delete_new_core&server=local
    
    wait
    
    curl $index_management_path/index_management.php?status=create_new_core&server=local
    
    wait
    
    cp -r $solr_core_path/data $solr_new_core_path 

    wait
  # else create new core
  else
    echo ''
    echo "Creating a new core at $solr_new_core_path"
    
    mkdir $solr_new_core_path
    cp -r $solr_core_path/conf $solr_new_core_path/conf
    cp -r $solr_core_path/data $solr_new_core_path/data
    
    wait
    
    curl $index_management_path/index_management.php?status=create_new_core&server=local
    
    wait
  fi
else              
  reload_core="reload_original_core"
  empty_core="empty_original_core"
  update_core="update_original_core"
  optimize_core="optimize_original_core"
  
  schema=$solr_core_path/schema.xml
fi

#--------------------------------------------------------------
# the regular index actions
#--------------------------------------------------------------
case $flag in
  #--------------------------------------------------------------
  # --preprocess: only process the data and extract its schemas
  #--------------------------------------------------------------
   "--preprocess"|"-p") 
    echo "";

    echo "Re-compiling the texts to CMDI..."
    time perl $zt | parallel time perl $ozt

    # create an overview first
    echo "Compiling the CMDI list and extract XML schemas...(1/9)"
    time perl $createlist
    
    # retrieve the MdProfile names
    echo "Retrieving the MdProfile names...(2/9)"
    time php $profilenames
    echo "";
     ;;
  #--------------------------------------------------------------
  # --compile: only compile the data and its schemas
  #--------------------------------------------------------------     
   "--compile"|"-c") 
    echo "";   
    # create XSLT stylesheet to map each CMDI to indexing format
    echo "Compiling the XSLT stylesheets per profile...(3/9)"
    time perl $cmdi2xslt
    
    echo "Compiling the index data files...(4/9)"
    time perl $cmdi2schema
    echo "";
     ;;
  #--------------------------------------------------------------
  # --index: only index the data 
  #--------------------------------------------------------------         
   "--index"|"-i") 
    echo "";
    echo "Starting the indexing..."

    # create a schema.xml
    echo "Compiling the Lucene schema.xml file...(5/9)"
    time perl $schema2solr > $schema
  
    # extract the labels
    echo "Extracting the labels...(6/9)"
    time perl $labels2solr > $labels4user
  
    # restart the Solr server
    echo "Reload the cmdi Solr core...7/9)"
    time curl $index_management_path/index_management.php?status=$reload_core&server=local
    
    wait
    
    if test "$mode" == "new"; then  
      echo "Doing updates to the index...(8/9)"
      time time curl $index_management_path/do_index_updates.php
      
      wait
      
      echo "Optimizing the index...(9/9)"
      time curl $index_management_path/index_management.php?status=$optimize_core&server=local      
      
      wait  
    else
      # delete the index
      echo "Emptying the index...(8/9)"
      time curl $index_management_path/index_management.php?status=$empty_core&server=local
  
      wait
    
      # update the index
      echo "Updating and optimizing the index...(9/9)"
      time curl $index_management_path/index_management.php?status=$update_core&server=local
    
      wait
    
      time curl $index_management_path/index_management.php?status=$optimize_core&server=local
    
      wait       
    fi
    
    echo "SUCCESS! The indexing procedure has been finished!"   
    echo ""; 
     ;;
  #--------------------------------------------------------------
  # --all: do it all at once 
  #--------------------------------------------------------------     
   "--all"|"-a") 
    echo "";
    echo "Starting the indexing procedure..."

    echo "Re-compiling the texts to CMDI..."
    time perl $zt | parallel time perl $ozt
   
    # create an overview first
    echo "Compiling the CMDI list and extract XML schemas...(1/9)"
    time perl $createlist
    
    # retrieve the MdProfile names
    echo "Retrieving the MdProfile names...(2/9)"
    time php $profilenames   
   
    # create XSLT stylesheet to map each CMDI to indexing format
    echo "Compiling the XSLT stylesheets per profile...(3/9)"
    time perl $cmdi2xslt
    
    echo "Compiling the index data files...(4/9)"
    time perl $cmdi2schema
    
    wait
       
    #break off NOW if this step fails!!!
    if [ "$?" -eq "0" ]; then
      # create a schema.xml
      echo "Compiling the Lucene schema.xml file...(5/9)"
      time perl $schema2solr > $schema
    
      # extract the labels
      echo "Extracting the labels...(6/9)"
      time perl $labels2solr > $labels4user
    
      # restart the Solr server
      echo "Reload the cmdi Solr core...7/9)"
      time curl $index_management_path/index_management.php?status=$reload_core&server=local
      
      wait
      
      if test "$mode" == "new"; then  
        echo "Doing updates to the index...(8/9)"
        time time curl $index_management_path/do_index_updates.php
        
        wait
        
        echo "Optimizing the index...(9/9)"
        time curl $index_management_path/index_management.php?status=$optimize_core&server=local      
        
        wait  
      else
        # delete the index
        echo "Emptying the index...(8/9)"
        time curl $index_management_path/index_management.php?status=$empty_core&server=local
    
        wait
      
        # update the index
        echo "Updating and optimizing the index...(9/9)"
        time curl $index_management_path/index_management.php?status=$update_core&server=local
      
        wait
      
        time curl $index_management_path/index_management.php?status=$optimize_core&server=local
      
        wait       
      fi
      
      echo "SUCCESS! The indexing procedure has been finished!"
      echo "";
    else
      echo "Error: index data could not be compiled. Indexing procedure stopped."
      echo "";
    fi
     ;;    
  #-----------------------------------------------------------------------
  # --help: print out instructions on how to use this script 
  #-----------------------------------------------------------------------          
   "--help"|"-h")
    echo "";
    echo "Usage: [arguments] [optional if a new index needs to be created: new]";
    printf "\t%-20s\t%-20s\n" "--preprocess or -p" "only process the data and extract its schemas";
    printf "\t%-20s\t%-20s\n" "--compile or -c" "only compile the data and its schemas";
    printf "\t%-20s\t%-20s\n" "--index or -i" "only index the data";
    printf "\t%-20s\t%-20s\n" "--all or -a" "do it all at once";
    echo "";
    printf "\t%-20s\t%-20s\n" "-i new" "index the data in a new core";
    
    echo "";   
     ;;     
  #--------------------------------------------------------------
  # catch all: print out instructions
  #--------------------------------------------------------------          
   *) 
    echo "";
    echo "Please provide a parameter.";
    echo "See $0 --help for more information.";
    echo "";
   ;;
esac

# print time needed to execute the script
END=$(date +%s)
DIFF=$(( $END - $START ))
echo ""
echo "It took $DIFF seconds to execute this script."
echo "";

exit 1