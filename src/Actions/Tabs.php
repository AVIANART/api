<?php
    namespace App\Actions;

    use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
    use Laminas\Cache\Service\StorageAdapterFactoryInterface;
    use Psr\Container\ContainerInterface;
    use Slim\Http\ServerRequest as Request;
    use Slim\Http\Interfaces\ResponseInterface as Response;

    /*
     * @OA\Info(title="Handles tabs for the customizer UI", version="0.1")
     */
    class Tabs {
        private $settings;
        private $container;

        public function __construct(ContainerInterface $container) {
            $this->settings = $container->get('settings');
            $this->container = $container;
        }

        private function getGuiTabs(String $branch="DR") {
            $basePath = $this->settings['z3r']['branches'][$branch];

            $tabs = array();

            foreach(glob($basePath . "/resources/app/gui/randomize/**/*.json") as $file) {
                $sections = explode("/", str_replace($basePath . "/resources/app/gui/randomize/", "", $file));
                $tabs[$sections[0]][pathinfo($sections[1], PATHINFO_FILENAME)] = json_decode(file_get_contents($file), true);
            }

            $translations = $this->getTranslationTable($branch);
            $helpText = $this->getHelpText($branch);
            $friendlyNames = $this->getFriendlyNames($branch);
            $defaults = $this->getDefaults($branch);

            foreach($tabs as $tabName=>$tabLayout) {
                //This will be each "tab" in the GUI (dungeon, enemizer, entrando, etc)
                foreach($tabLayout as $sectionName=>$sectionData) {
                    //This is each "section" in the tab
                    foreach($sectionData as $groupName=>$groupData) {
                        //This should be our actual fields.
                        foreach($groupData as $fieldName=>$fieldValue) {
                            $translatedFieldName = @$translations[@str_replace("entrando", "entrance", $tabName)][$fieldName];
                            $defaultValue = @$defaults[$translatedFieldName];
                            $defaultValueDisplay = $defaultValue;
                            if(is_bool($defaultValue))
                                $defaultValueDisplay = $defaultValue ? "true" : "false";
                            $tabs[$tabName][$sectionName][$groupName][$fieldName]['help'] = @str_replace("%(default)s",$defaultValueDisplay,$helpText[$translatedFieldName]);
                            $tabs[$tabName][$sectionName][$groupName][$fieldName]['default'] = $defaultValue;
                            if(array_key_exists('options', $tabs[$tabName][$sectionName][$groupName][$fieldName])) {
                                $newOptions = array();
                                foreach($tabs[$tabName][$sectionName][$groupName][$fieldName]['options'] as $option) {
                                    $newOptions[] = array(
                                        'value' => $option,
                                        'label' => @$friendlyNames["randomizer.".@str_replace("entrando", "entrance", $tabName).".$fieldName.$option"]
                                    );
                                }
                                $tabs[$tabName][$sectionName][$groupName][$fieldName]['options'] = $newOptions;
                            }
                            $tabs[$tabName][$sectionName][$groupName][$fieldName]['label'] = @$friendlyNames["randomizer.".@str_replace("entrando", "entrance", $tabName).".$fieldName"];
                            $tabs[$tabName][$sectionName][$groupName][$fieldName]['yaml_name'] = $translatedFieldName;
                        }
                    }
                }
            }

            return $tabs;
        }

        private function getBranchMeta(String $branch="DR") {
            $basePath = $this->settings['z3r']['branches'][$branch];
            $currentDir = getcwd();
            chdir($basePath);
            $meta = array(
                'branch' => $branch,
                'version' => trim(shell_exec('python3 -c "import Main; exec(\'try: import OverworldShuffle \nexcept ImportError: OverworldShuffle = {}\'); print(\'DR: \' + Main.__version__ + \'\nOWR: \' + OverworldShuffle.__version__) if hasattr(OverworldShuffle, \'__version__\') else print(\'DR: \' + Main.__version__)"'))
            );
            chdir($currentDir);
            return $meta;
        }

        private function getTranslationTable(String $branch="DR") {
            $basePath = $this->settings['z3r']['branches'][$branch];

            $table = array();

            $rawPython = file_get_contents($basePath . "/source/classes/constants.py");
            $rawPython = explode("SETTINGSTOPROCESS = ", $rawPython);
            $table = json_decode(preg_replace("/,\n.+\}/","}",str_replace("'","\"",$rawPython[1])), true, 512, JSON_PARTIAL_OUTPUT_ON_ERROR);
            return $table['randomizer'];
        }

        private function getDefaults(String $branch="DR") {
            $cwd = getcwd();
            chdir($this->settings['z3r']['branches'][$branch]);
            $rawDefaults = shell_exec("python3 -c \"import json; from CLI import parse_settings; print(json.dumps(parse_settings()))\"");
            $defaults = json_decode($rawDefaults, true);
            chdir($cwd);
            return $defaults;
        }

        private function getHelpText(String $branch="DR") {
            $basePath = $this->settings['z3r']['branches'][$branch];

            $helpText = array();

            $helpText = json_decode(file_get_contents($basePath . "/resources/app/cli/lang/en.json"), true);

            return $helpText['help'];
        }

        private function getFriendlyNames(String $branch="DR") {
            $basePath = $this->settings['z3r']['branches'][$branch];

            $friendlyNames = array();

            $friendlyNames = json_decode(file_get_contents($basePath . "/resources/app/gui/lang/en.json"), true);

            return $friendlyNames['gui'];
        }

        /**
         * @OA\Get(
         *     path="/v1/tabs/{branch}",
         *     @OA\Response(response="200", description="Return the gui tabs for a branch"),
         *     @OA\Parameter(name="branch", in="path", description="Which branch to retrieve tabs for", required=false, allowEmptyValue=true, @OA\Schema(type="string"))
         * )
         */
        public function __invoke(Request $request, Response $response, array $args): Response {
            $pool = new CacheItemPoolDecorator($this->container->get(StorageAdapterFactoryInterface::class));
            
            if($args['branch'] ?? false) {
                $tabs = $pool->getItem('tabs_' . $args['branch']);
                if(!$tabs->isHit())
                    $tabs->set(array('tabs' => $this->getGuiTabs($args['branch']), 'meta' => $this->getBranchMeta($args['branch'])));
            } else {
                $tabs = $pool->getItem('tabs_Default');
                if(!$tabs->isHit())
                    $tabs->set(array('tabs' => $this->getGuiTabs(), 'meta' => $this->getBranchMeta()));
            }
            return $response->withJson($tabs->get());
        }
    }
?>