<?php
//
// Version 0.3
//
// Written by Tommy Botten Jensen
//   Modified by Loïs Taulelle
//   Adapted for RackTables >= 0.21.1 by Lars Vogdt
//
// The purpose of this plugin is to automatically import objects from (Puppets) YAML files.
// History
// Version 0.1:  Initial release
// Version 0.2:  Adaptation to 0.19.x, with additionnal specs from PSMN (see skel.yaml)
// Version 0.3:  + updated spyc.php to 0.6.1
//               + Adaptation to 0.21.x (new plugin format)
//
// Installation:
// 1) Copy script directory to plugins folder
// 2) Adapt the path to the import and backup directory via 'Configuration' => 'User interface' 
//    * YAML_IMPORTDIRS
//    * YAML_BACKUPDIR
// 3) make sure you have php-posix installed on your system
// 

// import YAML Parser library.
require_once "Spyc.php";

function plugin_yamlimport_info ()
{               
  return array
  (       
    'name' => 'yamlimport',
    'longname' => 'YAML import', 
    'version' => '0.3',
    'home_url' => 'https://github.com/lrupp/racktables-yamlimport',
  );
}  

function plugin_yamlimport_init ()
{
  global $tab;
  $tab['depot']['yamlimport'] = 'Import YAML objects';
  $tabhandler['depot']['yamlimport'] = 'ImportTab';
  $ophandler['depot']['yamlimport']['RunImport'] = 'RunImport';
  
  registerOpHandler ('depot', 'yamlimport', 'RunImport', 'RunImport');
  registerTabHandler ('depot', 'yamlimport', 'ImportTab');
  $interface_requires['yaml*'] = 'interface-config.php';
}

function plugin_yamlimport_install ()
{
  if (extension_loaded ('posix') === FALSE)
    throw new RackTablesError ('posix PHP module is not installed', RackTablesError::MISCONFIGURED);
  
  addConfigVar('YAML_IMPORTDIRS', 'import', 'string', 'yes', 'no', 'no', 'Directories to look for new yml files (separated by commata)');
  addConfigVar('YAML_BACKUPDIR', 'backup', 'string', 'yes', 'no', 'no', 'Directory to move imported yml files into (needs write permission for the Webserver).');
  addConfigVar('YAML_DEFAULTCONTACT', 'default@address.com', 'string', 'yes', 'no', 'no', 'Default contact for newly imported YAML file objects (if not defined via \'contact\' value in file)');
  addConfigVar('YAML_DEFAULT_SW_TYPE', 'SLES15', 'string', 'yes', 'no', 'no', 'Default Software Type to choose if not given in YAML file.');
  addConfigVar('YAML_UPDATE_CONTACT', 'false', 'string', 'yes', 'no', 'no', "'false'= use contact in DB; 'true'= use contact from YAML file");
  return TRUE;
}

function plugin_yamlimport_uninstall ()
{
  deleteConfigVar('YAML_IMPORTDIRS');
  deleteConfigVar('YAML_BACKUPDIR');
  deleteConfigVar('YAML_DEFAULTCONTACT');
  deleteConfigVar('YAML_DEFAULT_SW_TYPE');
  deleteConfigVar('YAML_UPDATE_CONTACT');
  return TRUE;
}

function plugin_yamlimport_upgrade ()
{
  return TRUE;
}

function printYAMLlegend()
{
  $knownTags=getKnownYAMLTags();
  addJS (<<<END
  function toggle(source){
    var inputs = document.querySelectorAll('input[type="checkbox"]');
    for(var i = 0; i < inputs.length; i++)
    {
      if(inputs[i].checked == false)
      {
        inputs[i].checked = true;
      } 
      else 
      {
        if(inputs[i].checked == true)
        {
          inputs[i].checked = false
        }
      }
    }
  }
END
  ,TRUE);
  echo "<style type='text/css'>\n  tr.has_problems\n  {\n    background-color: #ffa0a0;\n  }\n</style>\n";
  echo "Legend:<br/>";
  echo "<table align=right>
    <tr class=trerror><td colspan=2>Unknown object</td></tr>
    <tr><td class=row_even>Existing </td><td class=row_odd> object</td></tr>
    <tr><td class=row_even>Known tags in YAML file: </td><td style='font-size: small;'>";
  foreach ($knownTags as $tag)
  {
    echo "$tag,<br/>";
  }
  echo "</td></tr>\n</table>";
}

// Display the import tab
function ImportTab()
{
  global $nextorder;
  global $taglist;
  $info=plugin_yamlimport_info();
  $Version=$info['version'];
  $importdirs=array_map('trim', explode(',', getConfigVar('YAML_IMPORTDIRS')));
  startPortlet ('Import YAML objects');
  printYAMLlegend();
  foreach ($importdirs as $importdir){
    echo "<center>from $importdir</center>\n";
  }
  echo '<br/><table with=90% align=center border=0 cellpadding=5 cellspacing=0 align=center class=cooltable><tr valign=top>';
  // printOpFormIntro('RunImport', array ('mode' => 'one'));
  echo "<form method=post name=ImportObject action='?module=redirect&page=depot&tab=yamlimport&op=RunImport'>";
  echo '<tr valign=top><th>Assign tags</th><th align=center>Name</th><th align=center>Import ?</th></tr>';
  // taglist on display - left handed
  echo "<tr valign=top><td rowspan=\"0\">";
  printTagsPicker ();  
  echo '</td></tr>';

  $order = 'odd';
  // Find and read loop through all .yml files in the yaml directory.
  foreach ($importdirs as $importdir)
  {
    if(! is_dir($importdir))
    {
      throw new RackTablesError ("Defined YAML_IMPORTDIRS directory: $importdir does not exist or is not accessible.", RackTablesError::MISCONFIGURED);
    }
    if ($files = scandir("$importdir"))
    {
      foreach($files as $file)
      {
        // Since the files are named $FQDN.yml, we don't have to inspect the file during the first run.
        if(preg_match('/\.yml$/',$file))
        {
          $full_url="$importdir/$file";
          $name = preg_replace('/\.yml/','',$file);
          // Do a search on the row 'name' and retrieving the ID.
          // function getSearchResultByField ($tablename, $retcolumns, $scancolumn, $terms, $ordercolumn, $exactness)
          $object = getSearchResultByField
          (
            'RackObject',
            array ('id'),
            'name',
            "$name",
            '',
            2
          );

          if($object)
          {
            $url=makeHref
            (array
              (
                'page' => 'object',
                'tab' => 'default',
                'object_id' => $object[0]['id']
              )
            );
            echo "<tr class=row_${order}><td align=left><a href=\"$url\">" . $name .  "</a></td>\n";
          }
          else
          {
            echo "<tr class=trerror><td align=left>" . $name . "</td>\n";
          }
          echo "<td align='center'> <input type='checkbox' name='objectname[]' value='$full_url'></td></tr>\n";
          $order = $nextorder[$order];
        }
      }
    }
  }
  echo " <tr><td align='left'><font size='1em' color='gray'>Version ${Version}</font></td>\n";
  echo "     <td align='right'><input type='submit' name='got_very_fast_data' value='Import selected items'></td>\n";
  echo "     <td><input type='checkbox' onClick='toggle(this)' />(toggle selection)</td>\n";
  echo " </tr></table></td>\n";
  echo "</tr>\n";
  echo "</form>\n";
  echo "</table>\n";
  finishPortlet();
}

// The ophandler to insert objects (if any)
function RunImport()
{
  $taglist = isset ($_REQUEST['taglist']) ? $_REQUEST['taglist'] : array();
  $objectnames = $_POST['objectname'];

  global $dbxlink;
  global $remote_username;

  $knownTags=getKnownYAMLTags();
  $log = '';

  $default_contact=getConfigVar('YAML_DEFAULTCONTACT');
  $update_contact=strtolower(getConfigVar('YAML_UPDATE_CONTACT'));
  $default_sw_type_name=getConfigVar('YAML_DEFAULT_SW_TYPE');
  $default_os_dict_key = getdict($hw=$default_sw_type_name, $chapter=$operatingsystem_dict_chapter_id);
  if(!isset($os_dict_key))
  {
    // avoid problem with unset/unknown SW Type Name
    $os_dict_key=3687;  // SLES15
  }
  // We assume quite some type IDs below. Might be an idea to query them from the database 
  // directly... or alternatively define them in the config?
  //
  // $query = "SELECT dict_key FROM Dictionary WHERE dict_value='Server' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll ();
  // if($resultarray)
  // {
  //    $machinetype=$resultarray[0]['dict_key'];
  // }
  // else
  // {
  //     return showError("Could not identify 'Server' dictionary ID in Database");
  // }
  $machinetype=4;

  // ID for HW warranty
  // $query = "SELECT id FROM Attribute where name='HW warranty expiration' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //     $hw_warranty_id=$resultarray[0]['id'];
  // }
  // else
  // {
  //     return showError("Could not identify 'HW warranty expiration' attribute number in Database");
  // }
  $hw_warranty_id=22;

  // ID for Memorysize
  // $query = "select id FROM Attribute where name='RAM (GB)' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //     $memorysize_id=$resultarray[0]['id'];
  // }
  // else
  // {
  //     return showError("Could not identify 'RAM (GB)' attribute number in Database");
  // }
  $memorysize_id=10008;

  // ID for Orthos-ID
  // $query = "SELECT id FROM Attribute WHERE name='Orthos-ID' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //     $orthos_attribute_id=$resultarray[0]['id'];
  // }
  // else
  // {
  //     return showError("Could not identify 'Orthos-ID' attribute number in Database");
  // }
  $orthos_attribute_id=10004;

  // ID for contact person
  // $query = "SELECT id FROM Attribute WHERE name='contact person' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //     $contact_attribute_id = $resultarray[0]['id'];
  // }
  // else
  // {
  //    return showError("Could not identify 'contact person' attribute number in Database");
  // }
  $contact_attribute_id=14;

  // ID for machine architecture
  // $query = "SELECT id FROM Attribute WHERE name='architecture' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //     $architecture_id = $resultarray[0]['id'];
  // }
  // else
  // {
  //    return showError("Could not identify 'architecture' attribute number in Database");
  // }
  $architecture_id=10006;

  // ID for machine chapter id
  // $query = "SELECT chapter_id FROM AttributeMap where attr_id=$architecture_id LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //     $architecture_chapter_id = $resultarray[0]['chapter_id'];
  // }
  // else
  // {
  //    return showError("Could not identify the 'chapter_id' for the architecture map in Database");
  // }
  $architecture_chapter_id=10012;

  // HW type ID
  // $query = "SELECT id FROM Attribute WHERE name='HW type' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //   $hw_type_id=$resultarray[0]['id'];
  // }
  // else
  // {
  //   return showError("Could not identify the 'HW type' ID in Database");
  // }
  $hw_type_id=2;
  
  // Memory
  // $query = "SELECT id FROM Attribute WHERE name='RAM (GB)' LIMIT 1";
  // unset($result);
  // $result = usePreparedSelectBlade ($query);
  // $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
  // if($resultarray)
  // {
  //   $memory_size=$resultarray[0]['id'];
  // }
  // else
  // {
  //   return showError("Could not identify the 'RAM (GB)' ID in Database");
  // }
  $memory_size=10008;

  // Hypervisor
  $hypervisor_attribute_id=26;
  $hypervisor_dict_chapter_id=29;

  // Operating System
  $operatingsystem_dict_chapter_id=13;

  // FQDN
  $fqdn_attribute_id=3;

  // UUID
  $uuid_attribute_id=25;

  // Serialnumber
  $serialnumber_attribute_id='1';

  // handling of directory for parsed/imported YAML files
  $olddir=getConfigVar('YAML_BACKUPDIR');
  if (! is_dir("$olddir")){
    if (!mkdir("$olddir", 0775, true))
    {		  
      throw new RackTablesError ("Defined YAML_BACKUPDIR directory: $olddir does not exist and can not be created.", RackTablesError::MISCONFIGURED);
    }
  }
  
  foreach($objectnames as $file)
  {
    $file_array = spyc_load_file("$file");
    $name=$file_array[0]['name'];
    $yaml_file_array=$file_array[0]['parameters'];
    if ($debug)
    {	  
      $log .= "NAME: $name<br/>\n";
      foreach ( $yaml_file_array as $key => $params ) {
        $log .= "$key: $params<br/>\n";
      }
    }
    // At this point, $parameter_array contains all the data from the 
    // YAML file in a indexed array (hopefully).
    if (! isset($name))
    {
        throw new InvalidRequestArgException ('name', '', "Could not find 'name:' (tag and value) in file $file");
    }

    // check for unknown fields
    foreach ($yaml_file_array as $key => $params)
    {
      if (in_array("$key", $knownTags))
      {
        if ($debug)
          $log .= "  + found tag: $key in $objectname<br/>\n";
      } elseif ( preg_match('/^(ipaddress_|macaddress_|label_)/', $key))
      {
        if ($debug)
          $log .= "  + ignoring $key, parsing later<br/>\n";
      } else
      {
        $log .= "<font color=red>  - unkown tag: '$key' in $file</font><br/>\n";
      }
    }

    // getSearchResultByField ($tname, $rcolumns, $scolumn, $terms, $ocolumn = '', $exactness = 0|1|2)
    $object = getSearchResultByField
    (
      'RackObject',
      array ('id'),
      'name',
      $name,
      '',
      2
    );

    if($object)
    {
      // Object exists
      $id = $object[0]['id'];
      // Create a URL for the log message and report back to user
      $url=makeHref (array (
             'page' => 'object',
              'tab' => 'default',
        'object_id' => $id
      ));
      $log .= "<a href=\"$url\">" . $name .  "</a> already existed: updated<br/>\n<div class='tdleft' style='font-weight: normal;'><ul>";
    } 
    else 
    {
      // Object does not exist - create new
      //
      // We only need a unique name inside a machinetype - the rest of values
      // for the commitAddObject() function is optional.
      // So ignore label, asset_tag and taglist for the moment: 
      //   we update these values later
      $label=NULL;
      $asset_tag=NULL;
      $taglist = array();
      // MACHINETYPE
      if(isset($yaml_file_array['machinetype']))
      {
        // Type is 4, server, by default - but get it from the Dictionary to be sure
        $query = "SELECT dict_key FROM Dictionary WHERE dict_value='".$yaml_file_array['machinetype']."' LIMIT 1";
        unset($result);
        $result = usePreparedSelectBlade ($query);
        $resultarray = $result->fetchAll ();
        if($resultarray)
        {
          $machinetype=$resultarray[0]['dict_key'];
        }
        else
        {
          return showError("Could not identify '".$yaml_file_array['machinetype']."' dictionary ID in Database for $file");
        }
      }
      // Finally: create the new object:
      $id = commitAddObject ($yaml_name,$label,$machinetype,$asset_tag,$taglist);
      addLogEntry($id,"$name added successful via YAML import from $file");
      // report back to user
      $url=makeHref (array (
             'page' => 'object',
              'tab' => 'default',
        'object_id' => $id
      ));
      $log .= "<a href=\"$url\">" . $name .  "</a> added successful.<br/>\n<div class='tdleft' style='font-weight: normal;'><ul>";
    }
    //
    // Updating existing or creating new values below
    //
    // We assume that entries in the imported file always override existing entries in the database
    //

    // get some values from the DB to compare with new values later
    $object_details    = spotEntity ('object', $id);
    $object_attributes = getAttrValuesSorted($id);

    // LABEL
    if(isset($yaml_file_array['label']))
    {
      $label=$yaml_file_array['label'];
    }
    else
    {
      if (isset($object_details['label']))
      {
        $label=$object_details['label'];
      }
      else
      {
        $label=$yaml_name;
      }
    }
 
    // ASSET_TAG
    $asset_tag=NULL;
    if(isset($yaml_file_array['asset_tag']))
    {
      $asset_tag=$yaml_file_array['asset_tag'];
    }
    else
    {
      if(isset($yaml_file_array['orthos_id']))
      {
        // special handling for Orthos machines: they don't have an official 
        // asset tag, so use the serial number, if exists
        if (isset($yaml_file_array['serialnumber']))
        {
          $asset_tag=$yaml_file_array['serialnumber'];
        }
      }
      if(isset($object_details['asset_no']))
      {
        $asset_tag=$object_details['asset_no'];
      }
    }

    // DESCRIPTION
    $description='';
    if(isset($yaml_file_array['description']))
    {
      $description=$yaml_file_array['description'];
    }
    else
    {
      // do NOT update description in DB if comment (in DB) is not empty
      if(isset($object_details['comment']) && ($object_details['comment'] != '')){
        $description=$object_details['comment'];
      }
    }

    // Update object_id with current values for name, label asset_tag and description (comment)
    commitUpdateObject($id, $name, $label, NULL, $asset_tag, $description );

    // Hardware type (i.e. ProLiant DL380 G6a), Dict Chapter ID is '11';
    if(isset($yaml_file_array['servermodel']))
    {
      $HW_type=$yaml_file_array['servermodel'];
    }
    if(isset($yaml_file_array['productname']))
    {
      $HW_type=$yaml_file_array['productname'];
    }
    if(isset($HW_type))
    {
      $hw_dict_key = getdict($hw="$HW_type", $chapter=11);
      if (isset($object_attributes[$hw_type_id]['value']))
      {
	$old_hw_type=$object_attributes[$hw_type_id]['value'];
	if ("$old_hw_type" != "$HW_type")
	{
          commitUpdateAttrValue($object_id = $id, $attr_id = $hw_type_id, $value = $hw_dict_key);
	  $log .= "<li>updated HW type from '$old_hw_type' to: $HW_type for $name</li>\n";
	  addLogEntry($id,"updated HW type from '$old_hw_type' to: $HW_type");
	}
      }
      else 
      {
	commitUpdateAttrValue ($object_id = $id, $attr_id = $hw_type_id, $value = $hw_dict_key);
	$log .= "<li>set HW type to: $HW_type for $name</li>\n";
	addLogEntry($id,"set HW type to: $HW_type");
      }
    }

    // Container
    if(isset($yaml_file_array['container']))
    {
      // select * from Object where objtype_id=1505 and name='atreju-cluster';
      $cluster = getSearchResultByField
      (
          'Object',
          array ('id'),
          'objtype_id=1505 AND name',
          $yaml_file_array['container'],
          '',
          2
      );
      if($cluster)
      {
          // Cluster exists - create Link Entities
          $cluster_id = $cluster[0]['id'];
          if (isset($object_details['container_dname']))
	  {
            $old_cluster = getSearchResultByField
            (
              'Object',
              array ('id'),
              'objtype_id=1505 AND name',
              $object_details['container_dname'],
              '',
              2
            );
	    $old_cluster_id=$old_cluster[0]['id'];
	    if ($old_cluster_id != $cluster_id)
            {
              // commitUpdateEntityLink(
              // $old_parent_entity_type, $old_parent_entity_id, $old_child_entity_type, $old_child_entity_id,
              // $new_parent_entity_type, $new_parent_entity_id, $new_child_entity_type, $new_child_entity_id);
              commitUpdateEntityLink('object', $old_cluster_id, 'object', $id,
                                     'object', $cluster_id, 'object', $id );
              $log .= "<li>moved $name from container: ". $object_details['container_dname'] ."(ID: ". $old_cluster_id .
		      ") to container: ". $yaml_file_array['container'] ." (ID: ". $cluster_id .")</li>\n";
	      addLogEntry($id,"moved $name from container: ".$object_details['container_dname']."to container: ". $yaml_file_array['container']);
	    }
	  }
	  else 
	  {
            commitLinkEntities('object', $cluster_id, 'object', $id );
	    $log .= "<li>added $name to container: ". $yaml_file_array['container'] ."</li>";
	    addLogEntry($id,"added $name to container: ". $yaml_file_array['container']);
          }	  
      }
      else
      {
        $log .= "<li><font color=red>Cluster: ".$yaml_file_array['container']." (in file $file) not found - skipping.</font></li>";
      }
    }

    // Memory - Note: we defined $memorysize_id before!
    if(isset($yaml_file_array['memorysize']))
    {
      $memory=(int) $yaml_file_array['memorysize'];
    }
    if(isset($yaml_file_array['memory_size']))
    {
      $memory=(int) $yaml_file_array['memory_size'];
    }
    if(isset($memory) && ("$memory" != ""))
    {
      if (isset($object_attributes[$memorysize_id]['value']))
      {
	$old_memory=$object_attributes[$memorysize_id]['value'];
	if ("$old_memory" != "$memory")
	{
	  commitUpdateAttrValue($object_id = $id, $attr_id = $memorysize_id, $value = $memory);
	  $log .= "<li>updated Memory from $old_memory to: $memory for $name</li>\n";
	  addLogEntry($id,"updated Memory from $old_memory to: $memory");
	}
      }
      else 
      {
        commitUpdateAttrValue($object_id = $id, $attr_id = $memorysize_id, $value = $memory);
	$log .= "<li>set Memory to: $memory G for $name</li>\n";
	addLogEntry($id,"set Memory to: $memory G");
      }
    }

    // Warranty - Note: we defined hw_warranty_id before!
    if(isset($yaml_file_array['warranty']))
    {
      $dt=DateTime::createFromFormat('Y-m-d', $yaml_file_array['warranty']);
      $warranty=$dt->getTimestamp();
      if (isset($object_attributes[$hw_warranty_id]['value']))
      {
        $old_warranty=$object_attributes[$hw_warranty_id]['value'];
        if ($old_warranty != $warranty)
	{
          commitUpdateAttrValue ($object_id = $id, $attr_id = $hw_warranty_id, $value = $warranty);
	  $log .= "<li>updated HW warranty (from: $old_warranty) to: $warranty for $name</li>\n";
	  addLogEntry($id,"updated HW warranty (from: $old_warranty) to: $warranty");
	}
      }
      else
      {
        commitUpdateAttrValue ($object_id = $id, $attr_id = $hw_warranty_id, $value = $warranty);
	$log .= "<li>set HW warranty to: $warranty for $name</li>\n";
	addLogEntry($id,"set HW warranty to: $warranty");
      }
    }

    // Orthos ID - Note: we defined orthos_attribute_id before
    if(isset($yaml_file_array['orthos_id']))
    {
      $orthos_id=(int) $yaml_file_array['orthos_id'];
      if (isset($object_attributes[$orthos_attribute_id]['value']))
      {
        $old_orthos_id=$object_attributes[$orthos_attribute_id]['value'];
	if ($old_orthos_id != $orthos_id)
	{
	  commitUpdateAttrValue($object_id = $id, $attr_id = $orthos_attribute_id, $value = $orthos_id);
	  $log .= "<li>updated Orthos ID from $old_orthos_id to: $orthos_id for $name</li>\n";
	  addLogEntry($id,"updated Orthos ID from $old_orthos_id to: $orthos_id");
	}
      }
      else 
      {
        commitUpdateAttrValue($object_id = $id, $attr_id = $orthos_attribute_id, $value = $orthos_id);
	$log .= "<li>set Orthos-ID to: $orthos_id for $name</li>\n";
	addLogEntry($id,"set Orthos-ID to: $orthos_id");
      }
    }

    // FQDN
    if(isset($yaml_file_array['fqdn']))
    {
      $fqdn=strtolower($yaml_file_array['fqdn']);
      if (isset($object_attributes[$fqdn_attribute_id]['value']))
      {
	$old_fqdn=$object_attributes[$fqdn_attribute_id]['value'];
	if ("$old_fqdn" != "$fqdn")
	{
          commitUpdateAttrValue ($object_id = $id, $attr_id = $fqdn_attribute_id, $value = $fqdn);
	  $log .= "<li>updated FQDN from $old_fqdn to: $fqdn for $name</li>\n";
	  addLogEntry($id,"updated FQDN from $old_fqdn to: $fqdn");
	}
      }
      else 
      {
        commitUpdateAttrValue ($object_id = $id, $attr_id = $fqdn_attribute_id, $value = $fqdn);
	$log .= "<li>set FQDN to: $fqdn for $name</li>\n";
	addLogEntry($id,"set FQDN to: $fqdn");
      }
    }

    // UUID
    if(isset($yaml_file_array['uuid']))
    {
      $uuid=strtolower($yaml_file_array['uuid']);
      if (isset($object_attributes[$uuid_attribute_id]['value']))
      {
	$old_uuid=$object_attributes[$uuid_attribute_id]['value'];
	if ("$old_uuid" != "$uuid")
	{
	  commitUpdateAttrValue ($object_id = $id, $attr_id = $uuid_attribute_id, $value = $uuid);
	  $log .= "<li>updated UUID from $old_uuid to: $uuid for $name</li>\n";
	  addLogEntry($id,"updated UUID from $old_uuid to: $uuid");
	}
      }
      else 
      {
        commitUpdateAttrValue ($object_id = $id, $attr_id = $uuid_attribute_id, $value = $uuid);
	$log .= "<li>set UUID to $uuid for $name</li>\n";
	addLogEntry($id,"set UUID to $uuid");
      }
    }

    // OEM S/N 1. Attribute ID is '1'
    if(isset($yaml_file_array['serialnumber']))
    {
      $serialno=$yaml_file_array['serialnumber'];
      if (isset($object_attributes[$serialnumber_attribute_id]['value']))
      {
        $old_serialno=$object_attributes[$serialnumber_attribute_id]['value'];
	if ("$old_serialno" != "$serialno")
	{
	  commitUpdateAttrValue ($object_id = $id, $attr_id = $serialnumber_attribute_id, $value = $serialno);
	  $log .= "<li>updated Serialnumber from '$old_serialno' to: $serialno for $name</li>\n";
	  addLogEntry($id,"updated Serialnumber from '$old_serialno' to: $serialno");
	}
      }
      else 
      {
        commitUpdateAttrValue ($object_id = $id, $attr_id = $serialnumber_attribute_id, $value = $serialno);
	$log .= "<li>set Serialnumber to: $serialno for $name</li>\n";
	addLogEntry($id,"set Serialnumber to: $serialno");
      }
    }

    // Architecture
    if(isset($yaml_file_array['architecture']))
    {
      // check for existing architecture in chapter - or create a new architecture 
      $architecture=strtolower($yaml_file_array['architecture']);
      $architecture_key=getdict($hw="$architecture", $chapter=$architecture_chapter_id);
      if (isset($object_attributes[$architecture_id]['value']))
      { 
	$old_architecture=strtolower($object_attributes[$architecture_id]['value']);
	if ("$old_architecture" != "$architecture")
	{
	  commitUpdateAttrValue ($object_id = $id, $attr_id = $architecture_id, $value = $architecture_key );
	  $log .= "<li>updated Architecture from $old_architecture to: $architecture for $name</li>\n";
	  addLogEntry($id,"updated Architecture from $old_architecture to: $architecture");
	}
      }
      else 
      {
        commitUpdateAttrValue ($object_id = $id, $attr_id = $architecture_id, $value = $architecture_key );
	$log .= "<li>set architecture to $architecture (ID: $architecture_key) for $name</li>\n";
	addLogEntry($id,"set architecture to $architecture");
      }
    }

    // Contact - set default contact, if neither defined in YAML file nor in object
    if (isset($yaml_file_array['contact']))
    {
      $contact=$yaml_file_array['contact'];
      if (isset($object_attributes[$contact_attribute_id]['value']))
      {
        $old_contact=$object_attributes[$contact_attribute_id]['value'];
        if ("$old_contact" != "$contact")
	{
	  if ( "$update_contact" === "true" )
	  {
	    commitUpdateAttrValue ($object_id = $id, $attr_id = $contact_attribute_id, $value = $contact);
	    $log .= "<li>updated Contact from $old_contact to: $contact for $name</li>\n";
	    addLogEntry($id,"updated Contact from $old_contact to: $contact");
	  }
	  else 
	  {
	    $log .= "<li>left Contact: $old_contact (instead of new: $contact) as 'YAML_UPDATE_CONTACT' in 'User interface' is set to 'true'</li>\n";
	  }
	}
      }
    }
    else
    {
      commitUpdateAttrValue ($object_id = $id, $attr_id = $contact_attribute_id, $value = $default_contact);
      $log .= "<li>set default contact $default_contact for $name</li>\n";
      addLogEntry($id,"set contact to default: $default_contact");
    }

    // Operating system string, Dict Chapter ID is '13'.
    if (isset($yaml_file_array['operatingsystem']) && isset($yaml_file_array['operatingsystemrelease']))
    {
      if(preg_match('/^SLES.*/i', $yaml_file_array['operatingsystem']))
      {
        $osrelease = $yaml_file_array['operatingsystem'] . "" . $yaml_file_array['operatingsystemrelease'];
      }
      else
      {
        $osrelease = $yaml_file_array['operatingsystem'] . " " . $yaml_file_array['operatingsystemrelease'];
      }
      $os_dict_key = getdict($hw=$osrelease, $chapter=$operatingsystem_dict_chapter_id);
      // hardcode the OS to the default, if there is no match
      if(!isset($os_dict_key))
      {
        $os_dict_key = $default_os_dict_key;
      }
      commitUpdateAttrValue($object_id = $id, $attr_id = '4', $value = $os_dict_key);
      $log .= "<li>set Operating System to: $osrelease (ID: $os_dict_key)</li>\n";
      addLogEntry($id,"set Operating System to: $osrelease");
    }

    // Hypervisor
    if(isset($yaml_file_array['hypervisor']))
    {
      $hypervisor=$yaml_file_array['hypervisor'];
      $hv_dict_key = getdict($hw="$hypervisor", $chapter=$hypervisor_dict_chapter_id);
      if (isset($object_attributes[$hypervisor_attribute_id]['value']))
      {
	$old_hypervisor=$object_attributes[$hypervisor_attribute_id]['value'];
	if ("$old_hypervisor" != "$hypervisor")
	{
	  commitUpdateAttrValue($object_id = $id, $attr_id = $hypervisor_attribute_id, $value = $hv_dict_key);
	  $log .= "<li>updated hypervisor from $old_hypervisor to: $hypervisor for $name</li>\n";
	  addLogEntry($id,"updated hypervisor from $old_hypervisor to: $hypervisor");
	}
      }
      else
      {
        commitUpdateAttrValue($object_id = $id, $attr_id = $hypervisor_attribute_id, $value = $hv_dict_key);
	$log .= "<li>set flag hypervisor to: $hypervisor for $name</li>\n";
	addLogEntry($id,"set flag hypervisor to: $hypervisor");
      }
    }

    // NICS
    $nics = explode(',',$yaml_file_array['interfaces'],9);
    // Go through all interfaces and add IP and MAC
    $count = count($nics);

    for ($i = 0; $i < $count; $i++)
    {
      // Remove newline from the field
      $nics[$i]=str_replace("\n","", $nics[$i]);
      // Only Document real interfaces, dont do bridges, bonds, vlan-interfaces
      // when they have no IP defined.
      if ( preg_match('(_|^(lo|sit|vnet|virbr|veth|peth))',$nics[$i]) != 0 )
      {
        // do nothing for now
      }
      else
      {
        // Get IP
        if (isset($yaml_file_array['ipaddress_' . $nics[$i]]))
        {
          $ip = $yaml_file_array['ipaddress_' . $nics[$i]];
        }
        // Get MAC
        if (isset($yaml_file_array['macaddress_' . $nics[$i]]))
        {
          $mac = $yaml_file_array['macaddress_' . $nics[$i]];
        }
        if (isset($yaml_file_array['label_' . $nics[$i]]))
        {
          $iflabel = $yaml_file_array['label_' . $nics[$i]];
        }
        else
        {
          $iflabel = 'Ethernet port';
        }
        // Get the Port ID (1000Base-T)
        $query = "SELECT id FROM PortOuterInterface WHERE oif_name REGEXP '^ *1000Base-T$' LIMIT 1";
        unset($result);
        $result = usePreparedSelectBlade ($query);
        $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
        if($resultarray)
        {
          $nictypeid=$resultarray[0]['id'];
        }
        else
        {
          // EXIT with error (under normal conditions).
          // For now: hardcode Type 24 is 1000Base-T
          $nictypeid=24;
        }
        // Remove newline from ip
	$ip=str_replace("\n","", $ip);
	// Check if the interface has an ip assigned
        $query = "SELECT object_id FROM IPv4Allocation WHERE object_id=$id AND name=\"$nics[$i]\" LIMIT 1";
        unset($result);
        $result = usePreparedSelectBlade ($query);
        $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
        if($resultarray)
        {
          //unset($id);
          $ipcheck=$resultarray;
        }
        // Check if it's been configured a port already
        $query = "SELECT id,iif_id FROM Port WHERE object_id=$id AND name=\"$nics[$i]\" LIMIT 1";
        unset($result);
        $result = usePreparedSelectBlade ($query);
        $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
        if($resultarray)
        {
          $portid = $resultarray[0]['id'];
          //unset($id);
          $portcheck=$resultarray;
        }
        // Add/update port
        if ( $resultarray[0]['type'] != 9 )
        {
          if ( count($portcheck) == 1 )
          {
            commitUpdatePort($id, $portid, $nics[$i], $nictypeid, $iflabel, "$mac", NULL);
	    // addLogEntry($id,"Updated NIC ". $nics[$i] ." ($iflabel) with MAC: $mac");
          }
          else
          {
            commitAddPort($object_id = $id, $nics[$i], $nictypeid,$iflabel,"$mac");
	    addLogEntry($id,"Added NIC ". $nics[$i] ." ($iflabel) with MAC: $mac");
            $log .= "<li>added NIC ". $nics[$i] ." ($iflabel) with MAC: $mac</li>\n";
	  }
        }
        else
        {
          // We've got a complex port: don't touch it, it raises an error with 'Database error: foreign key violation'
	}

        if (count($ipcheck) == 1 )
        {
          if( $ip )
          {
	    updateAddress(ip_parse($ip), $id, $nics[$i],'regular');
            addLogEntry($id,"Updated address: $ip for NIC ".$nics[$i]);
          }
        }
        else
        {
          if( $ip )
          {
            bindIpToObject(ip_parse($ip), $id, $nics[$i],'regular');
	    addLogEntry($id,"Added address: $ip to NIC ".$nics[$i]);
	    $log .= "<li>added address: $ip to NIC ".$nics[$i]."</li>\n";
	  }
        }
        // clean up
        unset($portcheck);
        unset($ipcheck);
        unset($ip);
        unset($mac);
      }
    }

    // Tags
    if(isset($yaml_file_array['tags']))
    {
      $tags=explode(",", $yaml_file_array['tags']);
      $log_tags='';
      foreach ($tags as $tag)
      {
         $query="SELECT id FROM TagTree WHERE tag='$tag' LIMIT 1";
         unset($result);
         $result = usePreparedSelectBlade ($query);
         $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
         if($resultarray)
         {
           $tag_id=$resultarray[0]['id'];
         }
         // do not create new tags, just assign the tag to the machine if it already exists in the database
         if(isset($tag_id))
	 {
	   $query="SELECT tag_id FROM TagStorage WHERE entity_id='$id' AND tag_id='$tag_id'";
           unset($result);
	   $result = usePreparedSelectBlade($query);
	   $resultarray = $result->fetchAll (PDO::FETCH_ASSOC);
	   if( ! $resultarray)
           {
             usePreparedExecuteBlade('INSERT INTO TagStorage SET entity_realm=?, entity_id=?, tag_id=?, tag_is_assignable=?, user=?, date=NOW()',
                                      array ('object', $id, $tag_id, 'yes', 'yaml_import script'));
	     $log_tags .= " '$tag',";
	   }
         }
      }
      $log_tags=rtrim($log_tags,',');
      $log .= "<li>added Tag(s): $log_tags to $name</li>\n";
      addLogEntry($id,"Added Tag(s): $log_tags");
    }

    // Finish lists of changed/added content: no more <li> below this line
    $log .= "</ul>\n";

    // Finally: Move the file out of the way into the olddir folder to cleanup the import folder
    $file_owner=fileowner("$file");
    $process_owner=posix_getuid();
    if ("$file_owner" === "$process_owner")
    {
      $filename=basename("$file");
      rename ("$file", "$olddir/$filename") or die("Unable to rename $file to $olddir/$filename");
      $log .= "Moved $file to $olddir<br/>";
    }
    else
    {
      $log.="Could not move $file into $olddir : please do manually now and fix file permissions next time.<br/>";
    }

    // finally finish the div containing the details for a machine
    $log .= "</div><br/>\n";
  }

  return showSuccess($log);
}

function getKnownYAMLTags ()
{
  $knownTags = array(
	  'fqdn',
	  'label', 
	  'machinetype', 
	  'asset_tag', 
	  'orthos_id', 
	  'serialnumber', 
	  'description', 
	  'servermodel', 
	  'productname', 
	  'container', 
	  'memory_size', 
	  'warranty',
	  'architecture',
	  'contact',
	  'operatingsystem',
	  'operatingsystemrelease',
	  'hypervisor',
	  'uuid',
	  'interfaces',
	  'ipaddress_',
	  'macaddress_',
	  'label_',
	  'tags',
          );
  return $knownTags;
}

function addLogEntry ($id,$message)
{
  global $dbxlink;
  global $remote_username;
  usePreparedExecuteBlade('INSERT INTO ObjectLog SET object_id=?, user=?, date=NOW(), content=?',
                           array ($id, $remote_username, $message));

}

// Gets the dict_key for a specified chapter_id . If it does not exist, we create a dictionary entry.
// A bit redundant with existing dict entries, won't hurt.
// Table Dictionary: (chapter_id,dict_key,'dict_value')
function getdict ($hw,$chapter) 
{
  try 
  {
    global $dbxlink;
    $query = "select dict_key from Dictionary where chapter_id='$chapter' AND dict_value LIKE '%$hw%' LIMIT 1";
    $result = usePreparedSelectBlade ($query);
    $array = $result->fetchAll (PDO::FETCH_ASSOC);
    if($array) 
    {
      return $array[0]['dict_key'];
    }
    else 
    {
      $dbxlink->exec("INSERT INTO Dictionary (chapter_id,dict_value) VALUES ('$chapter','$hw')");
      $squery = "select dict_key from Dictionary where dict_value ='$hw' AND chapter_ID ='$chapter' LIMIT 1";
      $sresult = usePreparedSelectBlade ($squery);
      $sarray = $sresult->fetchAll (PDO::FETCH_ASSOC);
      if($sarray) 
      {
        return $sarray[0]['dict_key'];
      }
      else 
      {
	// If it still has not returned, we are up shit creek. 
        return 0;
      }
    }
    $dbxlink = null;
  }
  catch(PDOException $e)
  {
    echo $e->getMessage();
  }
}

?>
