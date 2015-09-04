<?php

/**
 * Simple text template engine
 * @package Am_Utils
 */
class Am_SimpleTemplate
{
    protected $vars = array();
    protected $modifiers = array(
        'date' => 'amDate',
        'time' => 'amDateTime',
        'escape' => array('Am_Controller', 'escape'),
        'intval' => 'intval',
        'number_format' => 'number_format',
        'currency' => array('Am_Currency', 'render')
    );
    const NOT_FOUND = 'placeholder-not-found';

    function assignStdVars()
    {
        $this->assign('site_title', Am_Di::getInstance()->config->get('site_title', 'aMember Pro'));
        $this->assign('root_url', ROOT_URL);
        $this->assign('root_surl', ROOT_SURL);
        return $this;
    }

    function __get($k)
    {
        return array_key_exists($k, $this->vars) ? $this->vars[$k] : self::NOT_FOUND;
    }
    function __isset($k)
    {
        return array_key_exists($k, $this->vars);
    }
    function __set($k, $v)
    {
        $this->vars[$k] = $v;
    }
    function assign($k, $v = null)
    {
        if (is_array($k) && ($v === null))
        {
            $this->vars = array_merge($this->vars, $k);
        } else
            $this->vars[$k] = $v;
    }
    function render($text)
    {
        return preg_replace_callback('/%([a-zA-Z][a-zA-Z0-9_]*)(?:\.([a-zA-Z0-9_]+))?(\|[a-zA-Z0-9_|]+)?%/', array($this, '_replace'), $text);
    }
    public function _replace(array $matches)
    {
        $v = $this->__get($matches[1]);
        if (isset($matches[2]) && strlen($matches[2]))
        {
            $k = $matches[2];
            if (is_object($v))
                $v = (property_exists($v, $k) || isset($v->{$k})) ? $v->{$k} : self::NOT_FOUND;
            elseif (is_array($v))
                $v = array_key_exists($k, $v) ? $v[$k] : self::NOT_FOUND;
            else
                $v = self::NOT_FOUND;
        }

        if($v == self::NOT_FOUND) return $matches[0];

        if (is_array($v)) $v='Array';
        if (is_object($v)) $v='Object';

        if (isset($matches[3]) && strlen($matches[3]))
        {
            $modifiers = array_filter(explode('|', $matches[3]));
            foreach ($modifiers as $m)
            {
                if (empty($this->modifiers[$m])) continue;
                $v = call_user_func($this->modifiers[$m], $v);
            }
        }

        return $v;
    }
}