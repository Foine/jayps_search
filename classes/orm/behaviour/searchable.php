<?php

namespace JayPS\Search;

class Orm_Behaviour_Searchable extends \Nos\Orm_Behaviour
{
    protected static $_config = array();

    public static function init()
    {
        \Config::load('jayps_search::config', 'config');
        static::$_config = \Config::get('config');

        if (!empty(static::$_config['observed_models']) && is_array(static::$_config['observed_models'])) {
            foreach(static::$_config['observed_models'] as $name => $data) {

                \Event::register_function('config|'.$name, function(&$config) use ($data) {
                    $config['behaviours']['JayPS\Search\Orm_Behaviour_Searchable'] = $data['config_behaviour'];

                    \JayPS\Search\Orm_Behaviour_Searchable::add_relations($config, $data['primary_key']);
                });
            }
        }
    }
    public static function add_relations(&$config, $primary_key) {
        \Config::load('jayps_search::config', 'config');
        static::$_config = \Config::get('config');

        $has_many = array(
            'key_from'       => $primary_key,
            'model_to'       => 'JayPS\Search\Model_Keyword',
            'key_to'         => 'mooc_foreign_id',
            'cascade_save'   => false,
            'cascade_delete' => false,
        );
        for($i = 1; $i <= static::$_config['max_join']; $i++) {
            $config['has_many']['jayps_search_word_occurence'.$i] = $has_many;
        }
    }

    public function after_save(\Nos\Orm\Model $item)
    {
        if (!empty($this->_properties['fields'])) {

            $config = $this->get_config($item);

            $res = array();
            $res[$config['table_primary_key']] = $item->id;
            foreach($this->_properties['fields'] as $field) {
                if (mb_strpos($field, 'wysiwyg_') === 0) {
                    $wysiwyg = str_replace('wysiwyg_', '', $field);
                    $res[$field] = $item->wysiwygs->{$wysiwyg};
                } else {
                    $res[$field] = $item->{$field};
                }
            }

            $search = new Search($config);
            $search->add_to_index($res);
        }
    }

    public function before_save(\Nos\Orm\Model $item)
    {
        /** @todo save $item->get_diff somewhere to use it in after_save() and save time if there is no changes */
    }

    public function before_delete(\Nos\Orm\Model $item)
    {
        $config = $this->get_config($item);
        $search = new Search($config);
        $search->remove_from_index($item->id);
    }

    protected function get_config(\Nos\Orm\Model $item)
    {
        $config = array(
            'table'                     => $item->table(),
            'table_primary_key'         => self::get_first_primary_key($item),
            'table_fields_to_index'     => $this->_properties['fields'],
        );

        $config = array_merge(static::$_config, $config);

        if (isset($this->_properties['debug'])) {
            // if the propertie 'debug' is set in the configuration of the behaviour, we use it
            $config['debug'] = $this->_properties['debug'];
        }

        return $config;
    }

    protected static function get_first_primary_key($instance)
    {
        $primary_key = '';
        if (is_object($instance)) {
            $primary_key = $instance->primary_key();
        } else {
            // should be a class
            $primary_key = $instance::primary_key();
        }
        if (is_array($primary_key)) {
            $primary_key = \Arr::get($primary_key, 0);
        }
        return $primary_key;
    }


    private function d($o) {
        if (!empty($this->_properties['debug']) || !empty(static::$_config['debug'])) {
            print('<pre style="border:1px solid #0000FF; background-color: #CCCCFF; width:95%; height: auto; overflow: auto">');
            print_r($o);
            print('</pre>');
        }
    }

    public function before_query(&$options)
    {
        if (array_key_exists('where', $options)) {
            $where = $options['where'];

            foreach ($where as $k => $w) {

                if ($w[0] == 'keywords') {
                    self::d('before_query');
                    //self::d($w);

                    $class = $this->_class;
                    $table = $class::table();

                    $keywords = $w[1];
                    if (!empty($keywords)) {

                        if (!is_array($keywords)) {
                            $keywords = explode(' ', $keywords);
                        }

                        // sort keywords by length desc
                        uasort($keywords, function ($a, $b) {
                            return mb_strlen($a) < mb_strlen($b);
                        });

                        // remove keywords shorter than 'min_word_len' characters
                        $keywords = array_filter($keywords, function ($a) {
                            return mb_strlen($a) >= static::$_config['min_word_len'];
                        });

                        // remove duplicates
                        $keywords = array_unique($keywords);

                        // truncate to 'max_join' keywords
                        $keywords = array_slice($keywords, 0, static::$_config['max_join']);

                        self::d($keywords);

                        // $keywords as been modified, so keys are 0, 1, 2...
                        foreach ($keywords as $i => $keyword) {
                            $keyword = str_replace('%', '', $keyword);
                            if (mb_strpos($keyword, '*') !== false) {
                                $keyword = str_replace('*', '', $keyword) . '%';
                                $operator = 'LIKE';
                            } else {
                                $operator = '=';
                            }
                            $where[] = array(
                                array(static::$_config['table_liaison'] . ($i+1) . '.mooc_word', $operator,  $keyword),
                                array(static::$_config['table_liaison'] . ($i+1) . '.mooc_join_table', $table)
                            );
                            $options['related'][] = static::$_config['table_liaison'].($i+1);
                        }
                        $options['group_by'] = self::get_first_primary_key($class);
                    }
                    unset($where[$k]);
                }
            }
            $options['where'] = $where;
            //self::d($options);
        }
    }

}
