<?php

namespace App\Presenters;

use Nette,
	App\Model;


/**
 * Language presenter for all application presenters that have templates in lang folders.
 */
abstract class BugsLanguagesPresenter extends BugsBasePresenter
{
    protected function beforeRender() {
        parent::beforeRender();

        // formatRenderMethod edited in Presenter.php !!! - 2.3.2015 probably not need any more ???
        $this->setView($this->lang . "/" . $this->getView());
    }

}
