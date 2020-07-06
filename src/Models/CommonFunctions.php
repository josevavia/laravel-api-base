<?php

namespace LaravelApiBase\Models;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Str;

trait CommonFunctions
{

    /**
     * Returns a list of fields that can be searched / filtered by. This includes
     * all fillable columns, the primary key column, and the created_at and updated_at columns
     */
    public function searcheableFields() {
        return array_merge($this->fillable, [
            $this->primaryKey,
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn()
        ]);
    }

    /**
     * Retrieves all records based on request data passed in
     */
    public function getAll(Request $request)
    {
        $limit = $request->limit ?? 30;
        $builder =  $this->searchBuilder($request);

        return $builder->paginate($limit);
    }

    public function includeContains(Request $request, $builder) {
        if( $request->contain ) {
            $contains = explode(',', $request->contain);
            foreach($contains as $contain) {
                if( \method_exists($this, $contain) || strpos($contain, '.') !== false ) {
                    $builder->with(trim($contain));
                }
            }
        }

        return $builder;
    }

    public function includeCounts($request, $builder) {
        $count_info = $request->count ?? $request->with_count ?? null;

        if( $count_info ) {
            $counters = explode(",", $count_info);

            foreach($counters as $counter) {
                if( \method_exists($this, $counter) ) {
                    $builder->withCount($counter);
                }
            }
        }

        return $builder;
    }

    public function applySorts($request, $builder)
    {
        $sort_info = $request->sort ? $request->sort : null;

        if ($sort_info) {
            $sorts = explode(',', $sort_info);

            foreach ($sorts as $sort) {
                $sd = explode(":", $sort);
                if ($sd && count($sd) == 2) {
                    $builder->orderBy(trim($sd[0]), trim($sd[1]));
                }
            }
        }

        return $builder;
    }

    /**
     * Retrieves a record based on primary key id
     */
    public function getById($id, Request $request)
    {
        $builder = $this->where($this->primaryKey, $id);

        $builder = $this->includeCounts($request, $builder);
        $builder = $this->includeContains($request, $builder);
        $builder = $this->applySorts($request, $builder);

        return $builder->first();
    }

    public function store(Request $request)
    {
        $data = $this->create($request->all());

        $builder = $this->where('id', $data->id);
        $builder = $this->includeContains($request, $builder);
        $builder = $this->includeCounts($request, $builder);

        $dataModel = $builder->first();

        return $dataModel;
    }


    public function modify(Request $request, $id)
    {
        $dataModel = $this->findOrFail($id);

        if (!$dataModel) {
            throw new NotFoundHttpException("Resource not found");
        }

        $dataModel->fill($request->all());
        $dataModel->save();

        $builder = $this->where('id', $id);
        $builder = $this->includeContains($request, $builder);
        $builder = $this->includeCounts($request, $builder);

        $dataModel = $builder->first();

        return $dataModel;
    }

    public function remove($id)
    {
        $record = $this->find($id);

        if ($record) {
            try {
                return $record->delete();
            } catch (\Exception $e) {
                throw $e;
            }
        }

        return false;
    }

    /**
     * this returns key value pair for select options
     */
    public function getOptions()
    {
        $query = $this->select($this->option_key, $this->option_label)
                ->orderBy($this->option_label, 'asc')
                ->get();

        //convert data to standard object {value:'', label:''}
        $arr = [];
        foreach ($query as $x) {
            if ($x[$this->option_label]) {
                $arr[] = [
                  'value' => $x[$this->option_key],
                  'label' => $x[$this->option_label]
              ];
            }
        }

        return $arr;
    }


    public function search(Request $request)
    {
       $limit = $request->limit ?? 30;
       $builder =  $this->searchBuilder($request);

        return $builder->paginate($limit);
    }

    public function searchBuilder(Request $request) {

        $conditions = [];

        $builder = $this->where($conditions);
        $builder = $this->buildSearchParams($request, $builder);
        $builder = $this->includeContains($request, $builder);
        $builder = $this->includeCounts($request, $builder);
        $builder = $this->applySorts($request, $builder);
        return $builder;
    }


    public function count(Request $request)
    {
        $conditions = [];

        $builder = $this->where($conditions);
        $builder = $this->buildSearchParams($request, $builder);

        return $builder->count();
    }

    public function buildSearchParams(Request $request, $builder) {
        $operators = [
            '_not' => '!=',
            '_gt' => '>',
            '_lt' => '<',
            '_gte' => '>=',
            '_lte' => '<=',
            '_like' => 'LIKE',
            '_in' => true,
            '_notIn' => true,
            '_isNull' => true,
            '_isNotNull' => true
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $this->searcheableFields())) {
                switch ($key) {
                  default:
                      $builder->where($key, '=', $value);
                      break;
              }
            }

            // apply special operators based on the column name passed
            foreach($operators as $op_key => $op_type) {
                if( Str::endsWith($key, $op_key) === false ) {
                    continue;
                }

                $column_name = Str::replaceLast($op_key,'',$key);

                if( !in_array($column_name, $this->searcheableFields())){
                    continue;
                }

                if( $op_key == '_in' ) {
                    $builder->whereIn($column_name, explode(',', $value));
                } else if( $op_key == '_notIn' ) {
                    $builder->whereNotIn($column_name, explode(',', $value));
                } else if( $op_key == '_isNull' ) {
                    $builder->whereNull($column_name);
                } else if( $op_key == '_isNotNull' ) {
                    $builder->whereNotNull($column_name);
                } else if( $op_key == '_like' ) {
                    $builder->where($column_name, 'LIKE', "%{$value}%");
                } else {
                    $builder->where($column_name, $op_type, $value);
                }
            }
        }

        return $builder;
    }
}
