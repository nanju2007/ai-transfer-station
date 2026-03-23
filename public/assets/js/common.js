/**
 * 公共工具函数库
 * 基于 axios + TDesign MessagePlugin
 */

// ============================================
// API 请求封装
// ============================================
const AdminApi = axios.create({
    baseURL: '/api/admin/',
    timeout: 30000,
    withCredentials: true,
    headers: { 'Content-Type': 'application/json' }
});

const UserApi = axios.create({
    baseURL: '/api/user/',
    timeout: 30000,
    withCredentials: true,
    headers: { 'Content-Type': 'application/json' }
});

// 响应拦截器工厂
function setupInterceptors(instance, loginPath) {
    instance.interceptors.response.use(
        function (response) {
            var data = response.data;
            if (data && data.code !== undefined && data.code !== 0 && data.code !== 200) {
                TDesign.MessagePlugin.error(data.message || '请求失败');
                return Promise.reject(data);
            }
            return data;
        },
        function (error) {
            if (error.response) {
                var status = error.response.status;
                if (status === 401) {
                    TDesign.MessagePlugin.warning('登录已过期，请重新登录');
                    setTimeout(function () {
                        window.location.hash = loginPath;
                    }, 1000);
                } else if (status === 403) {
                    TDesign.MessagePlugin.error('没有权限执行此操作');
                } else if (status === 404) {
                    TDesign.MessagePlugin.error('请求的资源不存在');
                } else if (status === 422) {
                    var msg = error.response.data && error.response.data.message;
                    TDesign.MessagePlugin.error(msg || '参数验证失败');
                } else if (status >= 500) {
                    TDesign.MessagePlugin.error('服务器错误，请稍后重试');
                } else {
                    TDesign.MessagePlugin.error('请求失败: ' + status);
                }
            } else if (error.code === 'ECONNABORTED') {
                TDesign.MessagePlugin.error('请求超时，请检查网络');
            } else {
                TDesign.MessagePlugin.error('网络连接失败');
            }
            return Promise.reject(error);
        }
    );
}

setupInterceptors(AdminApi, '#/login');
setupInterceptors(UserApi, '#/login');

// ============================================
// 消息提示快捷方法
// ============================================
var Message = {
    success: function (text) { TDesign.MessagePlugin.success(text || '操作成功'); },
    error: function (text) { TDesign.MessagePlugin.error(text || '操作失败'); },
    warning: function (text) { TDesign.MessagePlugin.warning(text || '警告'); },
    info: function (text) { TDesign.MessagePlugin.info(text || '提示'); },
    loading: function (text) { return TDesign.MessagePlugin.loading(text || '加载中...'); }
};

// ============================================
// 日期格式化
// ============================================
function formatDate(dateStr, fmt) {
    if (!dateStr) return '-';
    fmt = fmt || 'YYYY-MM-DD HH:mm:ss';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return '-';
    var map = {
        'YYYY': d.getFullYear(),
        'MM': String(d.getMonth() + 1).padStart(2, '0'),
        'DD': String(d.getDate()).padStart(2, '0'),
        'HH': String(d.getHours()).padStart(2, '0'),
        'mm': String(d.getMinutes()).padStart(2, '0'),
        'ss': String(d.getSeconds()).padStart(2, '0')
    };
    var result = fmt;
    for (var key in map) {
        result = result.replace(key, map[key]);
    }
    return result;
}

// 相对时间
function timeAgo(dateStr) {
    if (!dateStr) return '-';
    var d = new Date(dateStr);
    var now = new Date();
    var diff = Math.floor((now - d) / 1000);
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 2592000) return Math.floor(diff / 86400) + '天前';
    return formatDate(dateStr, 'YYYY-MM-DD');
}

// ============================================
// 数字格式化
// ============================================
function formatNumber(num, decimals) {
    if (num === null || num === undefined) return '-';
    decimals = decimals !== undefined ? decimals : 0;
    var n = Number(num);
    if (isNaN(n)) return '-';
    return n.toLocaleString('zh-CN', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// 金额格式化（保留小数）
function formatMoney(num) {
    return formatNumber(num, 2);
}

// 大数字缩写
function formatCompact(num) {
    if (num === null || num === undefined) return '-';
    var n = Number(num);
    if (isNaN(n)) return '-';
    if (n >= 100000000) return (n / 100000000).toFixed(1) + '亿';
    if (n >= 10000) return (n / 10000).toFixed(1) + '万';
    return String(n);
}

// ============================================
// Hash 路由系统
// ============================================
var HashRouter = function () {
    function HashRouter() {
        this.routes = {};
        this.currentRoute = '';
        this.defaultRoute = '';
        this.beforeEach = null;
        this._onChange = this._handleChange.bind(this);
    }

    HashRouter.prototype.register = function (path, name) {
        this.routes[path] = name;
    };

    HashRouter.prototype.setDefault = function (path) {
        this.defaultRoute = path;
    };

    HashRouter.prototype.start = function () {
        window.addEventListener('hashchange', this._onChange);
        this._handleChange();
    };

    HashRouter.prototype.stop = function () {
        window.removeEventListener('hashchange', this._onChange);
    };

    HashRouter.prototype.navigate = function (path) {
        window.location.hash = path;
    };

    HashRouter.prototype.getCurrentRoute = function () {
        return this.currentRoute || this.defaultRoute;
    };

    HashRouter.prototype.getRouteName = function () {
        return this.routes[this.currentRoute] || '';
    };

    HashRouter.prototype._handleChange = function () {
        var hash = window.location.hash || '';
        var path = hash.replace(/^#/, '') || this.defaultRoute;

        // 路由守卫
        if (this.beforeEach) {
            var result = this.beforeEach(path, this.currentRoute);
            if (result === false) return;
            if (typeof result === 'string') {
                path = result;
                window.location.hash = path;
                return;
            }
        }

        this.currentRoute = path;

        if (this.onRouteChange) {
            this.onRouteChange(path);
        }
    };

    return HashRouter;
}();
