<?php

namespace PragmaRX\Tracker\Vendor\Laravel\Models;

class EventLog extends Base
{
    public $modelCacheEnabled = false;

    protected $table = 'tracker_events_log';

    protected $fillable = [
        'event_id',
        'class_id',
        'class_ref_id',
        'log_id',
        'extra_data',
        'created_at',
    ];

    protected $casts = ['extra_data' => 'array'];

    protected $_systemModel = false;

    public function event()
    {
        return $this->belongsTo($this->getConfig()->get('event_model'));
    }

    public function log()
    {
        return $this->belongsTo($this->getConfig()->get('log_model'));
    }

    public function systemClass()
    {
        return $this->belongsTo($this->getConfig()->get('system_class_model'), 'class_id');
    }

    public function getSystemModelAttribute()
    {
        if ($this->_systemModel === false) {
            $systemClass = $this->systemClass;

            if ($systemClass) {
                $id = $this->class_ref_id;
                $modelClass = $systemClass->name;

                $this->_systemModel = $modelClass::find($id);
            } else {
                $this->_systemModel = null;
            }
        }

        return $this->_systemModel;
    }
}
