<?php

namespace Modules\Project\Http\Controllers;

use App\Contact;
use App\User;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectCategory;
use Modules\Project\Entities\ProjectMember;
use Modules\Project\Entities\ProjectTask;
use Modules\Project\Entities\ProjectTimeLog;
use Modules\Project\Entities\ProjectTransaction;

use Modules\Project\Utils\ProjectUtil;


use App\Utils\ContactUtil;

use App\ExpenseCategory;
use App\Account;

use App\InvoiceScheme;

use App\Utils\ProductUtil;
use App\SellingPriceGroup;
use App\AccountTransaction;
use App\Business;
use App\BusinessLocation;
use App\CustomerGroup;
use App\Product;
use App\PurchaseLine;
use App\TaxRate;
use App\Transaction;
use App\Utils\BusinessUtil;
use App\Utils\TransactionUtil;
use App\Variation;
use Yajra\DataTables\Facades\DataTables;
use Spatie\Activitylog\Models\Activity;



class ProjectController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $contactUtil;
    protected $commonUtil;
    protected $projectUtil;
    protected $moduleUtil;
    protected $productUtil;


    /**
     * Constructor
     *
     * @param CommonUtil
     * @return void
     */
    public function __construct(ContactUtil $contactUtil,ProductUtil $productUtil,TransactionUtil $transactionUtil,Util $commonUtil,BusinessUtil $businessUtil, ProjectUtil $projectUtil, ModuleUtil $moduleUtil)
    {
        $this->contactUtil = $contactUtil;
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
        $this->commonUtil = $commonUtil;
        $this->projectUtil = $projectUtil;
        $this->businessUtil = $businessUtil;
        $this->moduleUtil = $moduleUtil;

        $this->dummyPaymentLine = ['method' => 'cash', 'amount' => 0, 'note' => '', 'card_transaction_number' => '', 'card_number' => '', 'card_type' => '', 'card_holder_name' => '', 'card_month' => '', 'card_year' => '', 'card_security' => '', 'cheque_number' => '', 'bank_account_number' => '',
        'is_return' => 0, 'transaction_no' => ''];
    }


    /**
     * Display a listing of the resource.
     * @return Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
        $user_id = auth()->user()->id;
        $statuses = Project::statusDropdown();
        
        //if project view is NULL, set default to list_view
        if (is_null(request()->get('project_view'))) {
            $project_view = 'list_view';
        } else {
            $project_view = request()->get('project_view');
        }

        if (!(auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $projects = Project::with('customer', 'members', 'lead', 'categories')->where('business_id', $business_id);

                //if not admin get assigned project only
                if (!$is_admin) {
                    $projects->whereHas('members', function ($q) use ($user_id) {
                        $q->where('user_id', $user_id);
                    });
                }
                
                // filter by status
                if (!empty(request()->get('status'))) {
                    $projects->where('status', request()->get('status'));
                }

                // filter by end date
                if (!empty(request()->get('end_date'))) {
                    if (request()->get('end_date') == 'overdue') {
                        $projects->where('end_date', '<', Carbon::today())
                                ->where('status', '!=', 'completed');
                    } elseif (request()->get('end_date') == 'today') {
                        $projects->where('end_date', Carbon::today())
                                ->where('status', '!=', 'completed');
                    } elseif (request()->get('end_date') == 'less_than_one_week') {
                        $projects->whereBetween('end_date', [Carbon::today(), Carbon::today()->addWeek()])
                            ->where('status', '!=', 'completed');
                    }
                }

                // filter by category id
                if (!empty(request()->get('category_id'))) {
                    $category_id = request()->get('category_id');
                    $projects->whereHas('categories', function ($q) use ($category_id) {
                        $q->where('id', $category_id);
                    });
                }

                if ($project_view == 'list_view') {
                    $projects = $projects->latest()
                                ->simplePaginate(10);

                    //check if user is lead/admin for the project
                    foreach ($projects as $key => $project) {
                        $is_lead = $this->projectUtil->isProjectLead($user_id, $project->id);

                        $projects[$key]['is_lead_or_admin'] = false;
                        if ($is_lead || $is_admin) {
                            $projects[$key]['is_lead_or_admin'] = true;
                        }
                    }

                    //dynamically render projects
                    $projects_html = View::make('project::project.partials.index')
                    ->with(compact('projects'))
                    ->render();
                } elseif ($project_view == 'kanban') {
                    $projects = $projects->get()->groupBy('status');
                    //sort projects based on status
                    $sorted_projects =[];
                    foreach ($statuses as $key => $value) {
                        if (!isset($projects[$key])) {
                            $sorted_projects[$key] = [];
                        } else {
                            $sorted_projects[$key] = $projects[$key];
                        }
                    }

                    $projects_html = [];
                    foreach ($sorted_projects as $key => $projects) {
                        //get all the project for particular board(status)
                        $cards = [];
                        foreach ($projects as $project) {
                            $edit = '';
                            if (auth()->user()->can('project.edit_project')) {
                                $edit = action('\Modules\Project\Http\Controllers\ProjectController@edit', ['id' => $project->id]);
                            }

                            $delete = '';
                            if (auth()->user()->can('project.delete_project')) {
                                $delete = action('\Modules\Project\Http\Controllers\ProjectController@destroy', ['id' => $project->id]);
                            }

                            $view = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]);

                            $overviewTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=overview';

                            $activitiesTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=activities';

                            $taskTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=project_task';

                            $timeLogTabUrl = '';
                            if (isset($project->settings['enable_timelog']) && $project->settings['enable_timelog']) {
                                $timeLogTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=time_log';
                            }

                            $docNoteTabUrl = '';
                            if (isset($project->settings['enable_notes_documents']) && $project->settings['enable_notes_documents']) {
                                $docNoteTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=documents_and_notes';
                            }

                            // check if user is lead
                            $is_lead = $this->projectUtil->isProjectLead($user_id, $project->id);

                            $invoiceTabUrl = '';
                            if ((isset($project->settings['enable_invoice']) && $project->settings['enable_invoice']) && ($is_lead || $is_admin)) {
                                $invoiceTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=project_invoices';
                            }

                            $settingsTabUrl = '';
                            if ($is_lead || $is_admin) {
                                $settingsTabUrl = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]).'?view=project_settings';
                            }

                            //if member then get their avatar
                            if ($project->members->count() > 0) {
                                $assigned_to = [];
                                foreach ($project->members as $member) {
                                    if (isset($member->media->display_url)) {
                                        $assigned_to[$member->user_full_name] = $member->media->display_url;
                                    } else {
                                        $assigned_to[$member->user_full_name] = "https://ui-avatars.com/api/?name=".$member->first_name;
                                    }
                                }
                            }

                            //display category as tag
                            $tags = [];
                            if ($project->categories->count() > 0) {
                                foreach ($project->categories as $category) {
                                    $tags[] = $category->name;
                                }
                            }

                            $cards[] = [
                                'id' => $project->id,
                                'title' => $project->name,
                                'viewUrl' => $view,
                                'editUrl' => $edit,
                                'editUrlClass' => 'edit_a_project',
                                'deleteUrl' => $delete,
                                'deleteUrlClass' => 'delete_a_project',
                                'assigned_to' => $assigned_to,
                                'hasDescription' => !empty($project->description) ?: false,
                                'endDate' => $project->end_date,
                                'tags' => $tags,
                                'customer' => !empty($project->customer) ? $project->customer->name : '',
                                'lead' => $project->lead->user_full_name,
                                'overviewTabUrl' => $overviewTabUrl,
                                'activitiesTabUrl' => $activitiesTabUrl,
                                'taskTabUrl' => $taskTabUrl,
                                'timeLogTabUrl' => $timeLogTabUrl,
                                'docNoteTabUrl' => $docNoteTabUrl,
                                'invoiceTabUrl' => $invoiceTabUrl,
                                'settingsTabUrl' => $settingsTabUrl,
                            ];
                        }

                        //get all the card & board title for particular board(status)
                        $projects_html[] = [
                            'id' => $key,
                            'title' => __('project::lang.'.$key),
                            'cards' => $cards,
                        ];
                    }
                }

                $output = [
                    'success' => true,
                    'projects_html' => $projects_html,
                    'msg' => __('lang_v1.success')
                ];
            } catch (Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong')
                ];
            }

            return $output;
        }

        //project statistics by status
        $project_stats = new Project;
        if (!$is_admin) {
            $project_stats = $project_stats->whereHas('members', function ($q) use ($user_id) {
                $q->where('user_id', $user_id);
            });
        }
        $project_stats = $project_stats->select(DB::raw('count(id) as count, status'))
            ->where('business_id', $business_id)
            ->groupBy('status')
            ->get();

        $due_dates = ProjectTask::dueDatesDropdown();
        $categories = ProjectCategory::forDropdown($business_id, 'project');
        return view('project::project.index')
            ->with(compact('statuses', 'due_dates', 'project_stats', 'categories', 'project_view'));
    }

    /**
     * Show the form for creating a new resource.
     * @return Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module') && auth()->user()->can('project.create_project')))) {
            abort(403, 'Unauthorized action.');
        }

        $users = User::forDropdown($business_id, false);
        $customers = Contact::customersDropdown($business_id, false);
        $statuses = Project::statusDropdown();
        $categories = ProjectCategory::forDropdown($business_id, 'project');

        return view('project::project.create')
            ->with(compact('users', 'customers', 'statuses', 'categories'));
    }

    /**
     * Store a newly created resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module') && auth()->user()->can('project.create_project')))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $input = $request->only('name', 'description', 'contact_id', 'status', 'lead_id');
            $input['start_date'] = !empty($request->input('start_date')) ? $this->commonUtil->uf_date($request->input('start_date')) : null;
            $input['end_date'] = !empty($request->input('end_date')) ? $this->commonUtil->uf_date($request->input('end_date')) : null;
            $input['business_id'] = $business_id;
            $input['created_by'] = $request->user()->id;

            // default settings for project
            $input['settings'] = [
                        'enable_timelog' => 1,
                        'enable_invoice' => 1,
                        'enable_notes_documents' => 1,
                        'members_crud_task' => 0,
                        'members_crud_note' => 0,
                        'members_crud_timelog' => 0,
                        'task_view' => 'list_view',
                        'task_id_prefix' => '#'
                    ];

            $members = $request->input('user_id');
            array_push($members, $request->input('lead_id'));

            $project = Project::create($input);
            $project_members = $project->members()->sync($members);

            //save project category
            $categories = $request->input('category_id');
            $project->categories()->sync($categories);

            // send notification to project members
            if (!empty($project_members['attached'])) {
                //check if user is a creator then don't notify him
                foreach ($project_members['attached'] as $key => $value) {
                    if ($value == $project->created_by) {
                        unset($project_members['attached'][$key]);
                    }
                }

                //Used for broadcast notification
                $project['title'] = __('project::lang.project');
                $project['body'] = __(
                    'project::lang.new_project_assgined_notification',
                    [
                    'created_by' => $request->user()->user_full_name,
                    'project' => $project->name
                    ]
                );
                $project['link'] = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]);

                $this->projectUtil->notifyUsersAboutAssignedProject($project_members['attached'], $project);
            }

            DB::commit();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (Exception $e) {
            DB::rollBack();

            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Show the specified resource.
     * @return Response
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module'))) {
            abort(403, 'Unauthorized action.');
        }
        
        $user_id = auth()->user()->id;

        //Get time project details.
        $project = Project::with('customer', 'members', 'categories')
                        ->withCount(['tasks as incomplete_task' => function ($query) {
                            $query->where('status', '!=', 'completed');
                        },
                        'documentsAndnote as note_and_documents_count' => function ($query) use ($user_id) {
                            $query->where('is_private', 0)
                            ->orWhere(function ($query) use ($user_id) {
                                $query->where('is_private', 1)
                                    ->where('created_by', $user_id);
                            });
                        }
                        ])
                        ->where('business_id', $business_id)
                        ->findOrFail($id);

        //Get time log details.
        $timelog = ProjectTimeLog::where('project_id', $id)
            ->select(DB::raw("SUM(TIMESTAMPDIFF(SECOND, start_datetime, end_datetime)) as total_seconds"))
           ->first();

        //Invoice paid.
        $invoice = ProjectTransaction::leftJoin('transaction_payments as TP', 'transactions.id', '=', 'TP.transaction_id')
            ->where('transactions.business_id', $business_id)
            ->where('pjt_project_id', $id)
            ->select(DB::raw('SUM(TP.amount) as paid'))
            ->first();

        //Invoice total.
        $transaction = ProjectTransaction::where('business_id', $business_id)
            ->where('pjt_project_id', $id)
            ->select(DB::raw('SUM(final_total) as total'))
            ->first();
            
        //check if user can create settings & task & time log
        $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
        $is_lead = $this->projectUtil->isProjectLead(auth()->user()->id, $id);
        $is_member = $this->projectUtil->isProjectMember(auth()->user()->id, $id);

        $is_lead_or_admin = false;
        if ($is_admin || $is_lead) {
            $is_lead_or_admin = true;
            // if admin get all project members for task filter
            $user_id = null;
        }

        $can_crud_task = $this->projectUtil->canMemberCrudTask($business_id, $user_id, $id);
        $can_crud_docus_note = $this->projectUtil->canMemberCrudNotes($business_id, $user_id, $id);
        $can_crud_timelog = $this->projectUtil->canMemberCrudTimelog($business_id, $user_id, $id);

        $due_dates = ProjectTask::dueDatesDropdown();
        $project_members = ProjectMember::projectMembersDropdown($id, $user_id);
        $statuses = ProjectTask::taskStatuses();
        $priorities = ProjectTask::prioritiesDropdown();

        //if view is NULL, set default to overview
        if (is_null(request()->get('view'))) {
            $tab_view = 'overview';
        } else {
            $tab_view = request()->get('view');
        }

        return view('project::project.show')
            ->with(compact('project', 'project_members', 'statuses', 'due_dates', 'priorities', 'timelog', 'can_crud_task', 'can_crud_docus_note', 'can_crud_timelog', 'is_lead_or_admin', 'transaction', 'invoice', 'tab_view'));
    }

    /**
     * Show the form for editing the specified resource.
     * @return Response
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module') && auth()->user()->can('project.edit_project')))) {
            abort(403, 'Unauthorized action.');
        }

        $users = User::forDropdown($business_id, false);
        $customers = Contact::customersDropdown($business_id, false);
        $statuses = Project::statusDropdown();
        $categories = ProjectCategory::forDropdown($business_id, 'project');
        $project = Project::with('members', 'categories')
                        ->where('business_id', $business_id)
                        ->findOrFail($id);

        return view('project::project.edit')
            ->with(compact('users', 'customers', 'statuses', 'project', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');
        
        if (!(auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module') && auth()->user()->can('project.edit_project')))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $input = $request->only('name', 'description', 'contact_id', 'status', 'lead_id');
            $input['start_date'] = !empty($request->input('start_date')) ? $this->commonUtil->uf_date($request->input('start_date')) : null;
            $input['end_date'] = !empty($request->input('end_date')) ? $this->commonUtil->uf_date($request->input('end_date')) : null;
            $members = $request->input('user_id');
            array_push($members, $request->input('lead_id'));

            $project = Project::where('business_id', $business_id)
                            ->findOrFail($id);

            $project->update($input);
            $project_members = $project->members()->sync($members);
            
            //update project category
            $categories = $request->input('category_id');
            $project->categories()->sync($categories);

            // send notification to project members
            if (!empty($project_members['attached'])) {
                //check if user is a creator then don't notify him
                foreach ($project_members['attached'] as $key => $value) {
                    if ($value == $project->created_by) {
                        unset($project_members['attached'][$key]);
                    }
                }
                
                //Used for broadcast notification
                $project['title'] = __('project::lang.project');
                $project['body'] = __(
                    'project::lang.new_project_assgined_notification',
                    [
                    'created_by' => $request->user()->user_full_name,
                    'project' => $project->name
                    ]
                );
                $project['link'] = action('\Modules\Project\Http\Controllers\ProjectController@show', ['id' => $project->id]);

                $this->projectUtil->notifyUsersAboutAssignedProject($project_members['attached'], $project);
            }

            DB::commit();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (Exception $e) {
            DB::rollBack();
            
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Remove the specified resource from storage.
     * @return Response
     */
    public function destroy($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'project_module') && auth()->user()->can('project.delete_project')))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $project = Project::where('business_id', $business_id)
                            ->findOrFail($id);

            $project->delete();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Update the project settings.
     * @param  Request $request
     * @return Response
     */
    public function postSettings(Request $request)
    {
        try {
            $input = $request->only('task_view');
            $input['enable_timelog'] = !empty($request->enable_timelog) ? 1 : 0;
            $input['enable_notes_documents'] = !empty($request->enable_notes_documents) ? 1 : 0;
            $input['enable_invoice'] = !empty($request->enable_invoice) ? 1 : 0;
            $input['members_crud_task'] = !empty($request->members_crud_task) ? 1 : 0;
            $input['members_crud_note'] = !empty($request->members_crud_note) ? 1 : 0;
            $input['members_crud_timelog'] = !empty($request->members_crud_timelog) ? 1 : 0;

            $input['task_id_prefix'] = !empty($request->task_id_prefix) ? $request->task_id_prefix : '#';

            $project_id = $request->get('project_id');
            $business_id = request()->session()->get('user.business_id');
            $project = Project::where('business_id', $business_id)
                        ->findOrFail($project_id);

            DB::beginTransaction();

            //Log activity
            activity()
                ->performedOn($project)
                ->withProperties(['from' => $project->settings, 'to' => $input])
                ->log('settings_updated');

            $project->settings = $input;
            $project->disableLogging();
            $project->save();
            DB::commit();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }
        
        return redirect()->action(
            '\Modules\Project\Http\Controllers\ProjectController@show',
            ['id' => $project_id]
            )->with('status', $output);
    }

    /**
    * update project status
    * @return Response
    */
    public function postProjectStatus($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $status = request()->get('status');

            $project = Project::where('business_id', $business_id)
                            ->findOrFail($id);

            $project->status = $status;
            $project->save();
            
            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    public function viewPurchase($projectid){


        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }

        $taxes = TaxRate::where('business_id', $business_id)
                        ->ExcludeForTaxGroup()
                        ->get();
        $orderStatuses = $this->productUtil->orderStatuses();


        $business_locations = BusinessLocation::forDropdown($business_id, false, true);

        $bl_attributes = $business_locations['attributes'];

        $business_locations = $business_locations['locations'];

        $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);

        $default_purchase_status = null;
        
        if (request()->session()->get('business.enable_purchase_status') != 1) {
            $default_purchase_status = 'received';
        }

        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }
        $customer_groups = CustomerGroup::forDropdown($business_id);

        $business_details = $this->businessUtil->getDetails($business_id);
        $shortcuts = json_decode($business_details->keyboard_shortcuts, true);

        $payment_line = $this->dummyPaymentLine;
        $payment_types = $this->productUtil->payment_types(null, true, $business_id);

        //Accounts
        $accounts = $this->moduleUtil->accountsDropdown($business_id, true);

        $common_settings = !empty(session('business.common_settings')) ? session('business.common_settings') : [];


        return view('project::project.purchase.create')
        ->with(compact('taxes', 'orderStatuses', 'business_locations', 'currency_details', 'default_purchase_status', 'customer_groups', 'types', 'shortcuts', 'payment_line', 'payment_types', 'accounts', 'bl_attributes', 'common_settings','projectid'));
    }

  
    

    public function viewSell($sellid){
        
        // search to find content
        $get_content = Project::where('id' , $sellid)->first();
        $content_id = $get_content->contact_id;


        $sale_type = request()->get('sale_type', '');

        if ($sale_type == 'sales_order') {
            if (!auth()->user()->can('so.create')) {
                abort(403, 'Unauthorized action.');
            }
        } else {
            if (!auth()->user()->can('direct_sell.access')) {
                abort(403, 'Unauthorized action.');
            }
        }
        

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for users quota
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        } elseif (!$this->moduleUtil->isQuotaAvailable('invoices', $business_id)) {
            return $this->moduleUtil->quotaExpiredResponse('invoices', $business_id, action('SellController@index'));
        }

        $walk_in_customer = $this->contactUtil->getWalkInCustomer($business_id);
        
        $business_details = $this->businessUtil->getDetails($business_id);
        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $business_locations = BusinessLocation::forDropdown($business_id, false, true);
        $bl_attributes = $business_locations['attributes'];
        $business_locations = $business_locations['locations'];

        $default_location = null;
        foreach ($business_locations as $id => $name) {
            $default_location = BusinessLocation::findOrFail($id);
            break;
        }

        $commsn_agnt_setting = $business_details->sales_cmsn_agnt;
        $commission_agent = [];
        if ($commsn_agnt_setting == 'user') {
            $commission_agent = User::forDropdown($business_id);
        } elseif ($commsn_agnt_setting == 'cmsn_agnt') {
            $commission_agent = User::saleCommissionAgentsDropdown($business_id);
        }

        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }
        $customer_groups = CustomerGroup::forDropdown($business_id);

        $payment_line = $this->dummyPaymentLine;
        $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);

        //Selling Price Group Dropdown
        $price_groups = SellingPriceGroup::forDropdown($business_id);

        $default_datetime = $this->businessUtil->format_date('now', true);

        $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

        $invoice_schemes = InvoiceScheme::forDropdown($business_id);
        $default_invoice_schemes = InvoiceScheme::getDefault($business_id);
        if (!empty($default_location)) {
            $default_invoice_schemes = InvoiceScheme::where('business_id', $business_id)
                                        ->findorfail($default_location->invoice_scheme_id);
        }
        $shipping_statuses = $this->transactionUtil->shipping_statuses();

        //Types of service
        $types_of_service = [];
        if ($this->moduleUtil->isModuleEnabled('types_of_service')) {
            $types_of_service = TypesOfService::forDropdown($business_id);
        }

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false);
        }

        $status = request()->get('status', '');

        $statuses = Transaction::sell_statuses();

        if ($sale_type == 'sales_order') {
            $status = 'ordered';
        }
        



        return view('project::project.sell.create')
            ->with(compact(
                'business_details',
                'taxes',
                'walk_in_customer',
                'business_locations',
                'bl_attributes',
                'default_location',
                'commission_agent',
                'types',
                'customer_groups',
                'payment_line',
                'payment_types',
                'price_groups',
                'default_datetime',
                'pos_settings',
                'invoice_schemes',
                'default_invoice_schemes',
                'types_of_service',
                'accounts',
                'shipping_statuses',
                'status',
                'sale_type',
                'statuses',
                'sellid',
                'content_id'
            ));
    }

    public function viewExpense($expenseid){
        if (!auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        
        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action('ExpenseController@index'));
        }

        $business_locations = BusinessLocation::forDropdown($business_id, false, true);

        $bl_attributes = $business_locations['attributes'];
        $business_locations = $business_locations['locations'];

        $expense_categories = ExpenseCategory::where('business_id', $business_id)
                                ->pluck('name', 'id');
        $users = User::forDropdown($business_id, true, true);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);
        
        $payment_line = $this->dummyPaymentLine;

        $payment_types = $this->transactionUtil->payment_types(null, false, $business_id);

        $contacts = Contact::contactDropdown($business_id, false, false);

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }

        if (request()->ajax()) {
            return view('expense.add_expense_modal')
                ->with(compact('expense_categories', 'business_locations', 'users', 'taxes', 'payment_line', 'payment_types', 'accounts', 'bl_attributes', 'contacts'));
        }

        return view('project::project.expense.create')
            ->with(compact('expenseid','expense_categories', 'business_locations', 'users', 'taxes', 'payment_line', 'payment_types', 'accounts', 'bl_attributes', 'contacts'));
      
    }
}
