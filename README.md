# AI模型计费管理平台
基于 webman 框架开发的 AI 模型计费管理平台，兼容 OpenAI / Anthropic 接口协议，支持多渠道负载均衡、用量计费、令牌管理等功能。
# 目录结构
```
├── app/
│   ├── controller/        # 控制器（admin/user/relay）
│   ├── middleware/         # 中间件
│   ├── model/             # 数据模型
│   ├── service/           # 业务服务
│   ├── queue/redis/       # Redis队列消费者
│   ├── relay/             # AI中转核心
│   └── view/              # 视图模板
├── config/                # 配置文件
├── database/              # SQL脚本
├── public/                # 静态资源
└── runtime/               # 运行时文件
```
# 关于部署
windows用户
双击 windows.bat 或者运行 php windows.php 启动
linux用户
`php start.php start
`

`
php start.php start -d
`
具体部署参考webman文档：[https://www.workerman.net/doc/webman/bt-install.html](https://www.workerman.net/doc/webman/bt-install.html)

如有bug，欢迎反馈
