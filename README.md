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

