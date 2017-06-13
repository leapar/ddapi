<?php

return [

    /**
     * opentsdb url
     */
    'tsdb_search_url' => 'http://tsdbrelay.docker:4242',
    //'tsdb_search_url' => 'http://172.29.231.70:4242',
    'tsdb_put_url' => 'http://tsdbrelay.docker:4242',
    //'tsdb_put_url' => 'http://172.29.231.177:4242',

    /**
     * put tag url
     */
    'tag_put_url' => 'http://bosun.docker:8101',
    //'tag_put_url' => 'http://172.29.231.177:8101',

];
