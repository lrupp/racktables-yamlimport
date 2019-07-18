# racktables-yamlimport
Automatically import RackTables objects from (Puppets) YAML files.

## Installation

1) copy the yamlimport directory (including it's content) into the plugins
   directory of your RackTables installation
2) Adapt the path to the import and backup directories via 'Configuration' =>
   'User interface' 
   * YAML_IMPORTDIRS : should point to the directories with your YAML files
   * YAML_BACKUPDIR  : place to move imported files 
     (both directories need to be writable by the user running the webserver
      if you want to get the files moved automatically after import)
3) Adjust the other 'User inferface' variables to your needs:
   * YAML_DEFAULTCONTACT  : is set, if there is no 'contact' defined in the
     YAML file
   * YAML_DEFAULT_SW_TYPE : is set, if there is no 'operatingsystem/
     operatingsystemrelease' defined
   * YAML_UPDATE_CONTACT  : update contact information from YAML file or leave 
     an existing entry in the database?
4) make sure you have php-posix installed on your system
5) go to 'Configuration' => 'Plugins' and enable the plugin named 'YAML import'

## Usage

1) Copy files with the name of the machine and the ending '.yml' into the 
   YAML_IMPORTDIRS directory. Make sure your webserver can read/write them.
2) Go to 'Objects' => 'Import YAML objects' (index.php?page=depot&tab=yamlimport)
3) select the machine you want to import by activating the checkbox
4) click on 'Import selected items'

* If a machine already exists, it's values will be updated, otherwise 
  a new object will be created.
* once imported, the file will be moved into the backupdir folder 
  (only if the webserver has write permissions to both folders and the file)

## Possible 'tags' in the YAML file

Please have a look at the page of the plugin ('Objects' => 'Import YAML objects'):
there is a Legend at the right side listing all recognized tags which can be 
used.

Please note: tags ending with an underscore (like 'ipaddress_' or 'label_') will 
only be recognized if they are extended with the interface names defined via 
the 'interfaces' tag. Example: if you define 
```
  interfaces: eth0
```
The following additional tags are recognized:
```
  ipaddress_eth0: 192.168.0.1
  macaddress_eth0: aa:bb:cc:dd:ee:ff
  label_eth0: eth0
```

## Test

The 'test' directory of this plugin contains some YAML files for testing.


