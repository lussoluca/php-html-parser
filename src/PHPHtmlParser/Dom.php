<?php
namespace PHPHtmlParser;

use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Dom\TextNode;

class Dom {
	
	/**
	 * Contains the root node of this dom tree.
	 *
	 * @var HtmlNode
	 */
	public $root;

	/**
	 * The raw version of the document string.
	 *
	 * @var string
	 */
	protected $raw;

	/**
	 * The document string.
	 *
	 * @var string
	 */
	protected $content;

	/**
	 * The original file size of the document.
	 *
	 * @var int
	 */
	protected $rawSize;
	
	/**
	 * The size of the document after it is cleaned.
	 *
	 * @var int
	 */
	protected $size;

	/**
	 * Attempts to load the dom from a string.
	 *
	 * @param string $str
	 * @chainable
	 */
	public function load($str)
	{
		$this->rawSize = strlen($str);
		$this->raw     = $str;

		// clean out none-html text
		$html = $this->clean($str);

		$this->size    = strlen($str);
		$this->content = new Content($html);

		$this->parse();
	}

	/**
	 * Loads the dom from a document file/url
	 *
	 * @param string $file
	 * @chainable
	 */
	public function loadFromFile($file)
	{
		$fp = fopen($file, 'r');
		$document = fread($fp, filesize($file));
		fclose($fp);

		return $this->load($document);
	}

	/**
	 * Find elements by css selector on the root node.
	 *
	 * @param string $selector
	 * @param int    $nth
	 * @return array
	 */
	public function find($selector, $nth = null)
	{
		return $this->root->find($selector, $nth);
	}

	/**
	 * Cleans the html of any none-html information.
	 *
	 * @param string $str
	 * @return string
	 */
	protected function clean($str)
	{
		// clean out the \n\r
		$str = str_replace(["\r\n", "\r", "\n"], '', $str);

        // strip out comments
        $str = preg_replace("'<!--(.*?)-->'is", '', $str);
        
        // strip out cdata
        $str = preg_replace("'<!\[CDATA\[(.*?)\]\]>'is", '', $str);
        
        // strip out <script> tags
        $str = preg_replace("'<\s*script[^>]*[^/]>(.*?)<\s*/\s*script\s*>'is", '', $str);
        $str = preg_replace("'<\s*script\s*>(.*?)<\s*/\s*script\s*>'is", '', $str);
        
        // strip out <style> tags
        $str = preg_replace("'<\s*style[^>]*[^/]>(.*?)<\s*/\s*style\s*>'is", '', $str);
        $str = preg_replace("'<\s*style\s*>(.*?)<\s*/\s*style\s*>'is", '', $str);
        
        // strip out preformatted tags
        $str = preg_replace("'<\s*(?:code)[^>]*>(.*?)<\s*/\s*(?:code)\s*>'is", '', $str);
        
        // strip out server side scripts
        $str = preg_replace("'(<\?)(.*?)(\?>)'s", '', $str);
        
        // strip smarty scripts
        $str = preg_replace("'(\{\w)(.*?)(\})'s", '', $str);

		return $str;
	}

	/**
	 * Attempts to parse the html in content.
	 */
	protected function parse()
	{
		// add the root node
		$this->root = new HtmlNode('root');
		$activeNode = $this->root;
		while ( ! is_null($activeNode))
		{
			$str = $this->content->copyUntil('<');
			if ($str == '')
			{
				$info = $this->parseTag();
				if ( ! $info['status'])
				{
					// we are done here
					$activeNode = null;
					continue;
				}

				// check if it was a closing tag
				if ($info['closing'])
				{
					while ($activeNode->getTag()->name() != $info['tag'])
					{
						$activeNode = $activeNode->getParent();
						if (is_null($activeNode))
						{
							break;
						}
					}
					if ( ! is_null($activeNode))
					{
						$activeNode = $activeNode->getParent();
					}
					continue;
				}

				$node = $info['node'];
				$activeNode->addChild($node);

				// check if node is self closing
				if ( ! $node->getTag()->isSelfClosing())
				{
					$activeNode = $node;
				}
			}
			else
			{
				// we found text
				$textNode = new TextNode($str);
				$activeNode->addChild($textNode);
			}
		}
	}

	/**
	 * Attempt to parse a tag out of the content.
	 *
	 * @return array
	 */
	protected function parseTag()
	{
		$return = [
			'status'  => false,
			'closing' => false,
			'node'    => null,
		];
		if ($this->content->char() != '<')
		{
			// we are not at the beginning of a tag
			return $return;
		}

		// check if this is a closing tag
		if ($this->content->fastForward(1)->char() == '/')
		{
			// end tag
			$tag = $this->content->fastForward(1)
			                     ->copyByToken('slash', true);
			// move to end of tag
			$this->content->copyUntil('>');
			$this->content->fastForward(1);
			$return['status']  = true;
			$return['closing'] = true;
			$return['tag']     = strtolower($tag);
			return $return;
		}

		$tag   = strtolower($this->content->copyByToken('slash', true));
		$node  = new HtmlNode($tag);

		// attributes
		while ($this->content->char() != '>' and
		       $this->content->char() != '/')
		{
			$space = $this->content->skipByToken('blank', true);
			if (empty($space))
			{
				break;
			}

			$name = $this->content->copyByToken('equal', true);
			if ($name == '/' OR
			    empty($name))
			{
				break;
			}

			if (empty($name))
			{
				$this->content->fastForward(1);
				continue;
			}

			$this->content->skipByToken('blank');
			if ($this->content->char() == '=')
			{
				$attr = [];
				$this->content->fastForward(1)
				              ->skipByToken('blank');
				switch ($this->content->char())
				{
					case '"':
						$attr['doubleQuote'] = true;
						$this->content->fastForward(1);
						$attr['value'] = $this->content->copyUntil('"', false, true);
						$this->content->fastForward(1);
						$node->getTag()->$name = $attr;
						break;
					case "'":
						$attr['doubleQuote'] = false;
						$this->content->fastForward(1);
						$attr['value'] = $this->content->copyUntil("'", false, true);
						$this->content->fastForward(1);
						$node->getTag()->$name = $attr;
						break;
					default:
						$attr['doubleQuote'] = true;
						$attr['value']       = $this->content->copyByToken('attr', true);
						$node->getTag()->$name = $attr;
						break;
				}
			}
			else
			{
				// no value attribute
				$node->getTag()->$name = [
					'value'       => null,
					'doubleQuote' => true,
				];
				$this->content->rewind(1);
			}
		}

		$this->content->skipByToken('blank');
		if ($this->content->char() == '/')
		{
			// self closing tag
			$node->getTag()->selfClosing();
			$this->content->fastForward(1);
		}
		
		$this->content->fastForward(1);

		$return['status'] = true;
		$return['node']   = $node;
		return $return;
	}
}
