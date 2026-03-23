<?php

namespace app\controller;

use support\Request;
use support\Response;

class PageController
{
    // ===== 管理端页面 =====
    public function adminLogin()
    {
        return view('admin/login');
    }

    public function adminDashboard()
    {
        return view('admin/dashboard', ['currentPage' => 'dashboard', 'pageTitle' => '仪表盘']);
    }

    public function adminChannels()
    {
        return view('admin/channels', ['currentPage' => 'channels', 'pageTitle' => '渠道管理']);
    }

    public function adminModels()
    {
        return view('admin/models', ['currentPage' => 'models', 'pageTitle' => '模型管理']);
    }

    public function adminPricing()
    {
        return view('admin/pricing', ['currentPage' => 'pricing', 'pageTitle' => '模型计费']);
    }

    public function adminRedemptions()
    {
        return view('admin/redemptions', ['currentPage' => 'redemptions', 'pageTitle' => '兑换码管理']);
    }

    public function adminUsers()
    {
        return view('admin/users', ['currentPage' => 'users', 'pageTitle' => '用户管理']);
    }

    public function adminLogs()
    {
        return view('admin/logs', ['currentPage' => 'logs', 'pageTitle' => '日志管理']);
    }

    public function adminSettings()
    {
        return view('admin/settings', ['currentPage' => 'settings', 'pageTitle' => '系统设置']);
    }

    public function adminProviders()
    {
        return view('admin/providers', ['currentPage' => 'providers', 'pageTitle' => '厂商管理']);
    }

    public function adminGroups()
    {
        return view('admin/groups', ['currentPage' => 'groups', 'pageTitle' => '分组管理']);
    }

    public function adminAnnouncements()
    {
        return view('admin/announcements', ['currentPage' => 'announcements', 'pageTitle' => '公告管理']);
    }

    public function adminModelCategories()
    {
        return view('admin/model-categories', ['currentPage' => 'model-categories', 'pageTitle' => '模型分类']);
    }

    public function adminTickets()
    {
        return view('admin/tickets', ['currentPage' => 'tickets', 'pageTitle' => '工单管理']);
    }

    // ===== 用户端页面 =====
    public function userLogin()
    {
        return view('user/login');
    }

    public function userRegister()
    {
        return view('user/register');
    }

    public function userDashboard()
    {
        return view('user/dashboard', ['currentPage' => 'dashboard', 'pageTitle' => '数据看板']);
    }

    public function userPlayground()
    {
        return view('user/playground', ['currentPage' => 'playground', 'pageTitle' => '操练场']);
    }

    public function userTokens()
    {
        return view('user/tokens', ['currentPage' => 'tokens', 'pageTitle' => '令牌管理']);
    }

    public function userLogs()
    {
        return view('user/logs', ['currentPage' => 'logs', 'pageTitle' => '使用日志']);
    }

    public function userWallet()
    {
        return view('user/wallet', ['currentPage' => 'wallet', 'pageTitle' => '钱包管理']);
    }

    public function userProfile()
    {
        return view('user/profile', ['currentPage' => 'profile', 'pageTitle' => '个人设置']);
    }

    public function userModelSquare()
    {
        return view('user/model-square', ['currentPage' => 'model-square', 'pageTitle' => '模型广场']);
    }

    public function userTickets()
    {
        return view('user/tickets', ['currentPage' => 'tickets', 'pageTitle' => '工单中心']);
    }

    public function index()
    {
        return redirect('/user/login');
    }

    // ===== 前台公开页面 =====
    public function home(): Response
    {
        // 检查是否已安装
        if (!file_exists(base_path() . '/install.lock')) {
            return redirect('/install');
        }
        return view('home/index');
    }

    public function pricing(): Response
    {
        return view('home/pricing');
    }
}
